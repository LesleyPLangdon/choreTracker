<?php
declare(strict_types=1);

namespace App\Rules;

use App\Chores\ChoreService;
use App\Clock;
use App\Http\ApiException;
use App\Ledger;
use PDO;

/**
 * The lock / unlock / forfeit rules for one kid's household day, and the only code that moves earnings between
 * the pending and spending buckets.
 *
 *  - Did the kid DO the required chores? (reported counts, even if late)  -> decides forfeiting, at end of day.
 *  - When does the money MOVE? When every required slot of the day is APPROVED -> pending becomes spending.
 *
 * Money moves are idempotent: every step is computed from the ledger's current sums for that day, so running
 * reconcile() twice (or two requests at once) never moves anything twice. Only append — nothing is edited.
 */
final class DayRules
{
    public function __construct(private PDO $pdo, private Clock $clock, private Ledger $ledger, private ChoreService $chores) {}

    // ---- status ------------------------------------------------------------------------------------

    /**
     * Work out a day's status from its required instances (does not change anything).
     * @return 'locked'|'waiting'|'unlocked'|'forfeited'
     */
    public function computeStatus(int $kidId, string $day, bool $ended): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM chore_instances WHERE kid_id = ? AND required_for_date = ?');
        $stmt->execute([$kidId, $day]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$statuses) {
            return 'unlocked'; // no required chores: unlocked from the start
        }
        if (count(array_unique($statuses)) === 1 && $statuses[0] === 'approved') {
            return 'unlocked';
        }
        if (!$ended) {
            return 'locked';
        }
        foreach ($statuses as $s) {
            if (in_array($s, ['assigned', 'sent_back', 'missed', 'expired'], true)) {
                return 'forfeited'; // a required slot was never reported done
            }
        }
        return 'waiting'; // everything reported, but not all approved yet
    }

    /**
     * Bring a day's status and money up to date. Call after anything that could change it.
     *
     * Rules for automatic changes: an unlocked day stays unlocked (nothing re-locks); a forfeited day stays forfeited
     * unless $allowRecover (back-fill); a parent's manual unlock/forfeit is never changed automatically.
     * Future days are left alone.
     */
    public function reconcile(int $householdId, int $kidId, string $day, bool $allowRecover = false): string
    {
        $time = $this->chores->householdTime($householdId);
        $today = $time->today();
        $ended = $day < $today;
        $row = $this->lockDay($kidId, $day);
        if ($day > $today) {
            return $row['status'];
        }
        $status = $row['status'];
        if ($row['override_by_parent_id'] === null) {
            $target = $this->computeStatus($kidId, $day, $ended);
            if ($status === 'unlocked') {
                $target = 'unlocked';
            } elseif ($status === 'forfeited' && !$allowRecover) {
                $target = 'forfeited';
            }
            $status = $target;
        }
        $this->saveStatus($row, $status);

        if ($status === 'unlocked') {
            $this->reverseForfeit($householdId, $kidId, $day);
            $this->moveToSpending($householdId, $kidId, $day);
        } elseif ($status === 'forfeited') {
            $this->forfeitPending($householdId, $kidId, $day);
        }
        return $status;
    }

    /** The kid_days row for a day (created if missing) and locked for the rest of the transaction. */
    public function lockDay(int $kidId, string $day): array
    {
        $now = $this->clock->nowSql();
        $this->pdo->prepare(
            "INSERT INTO kid_days (kid_id, local_date, status, created_at, updated_at) VALUES (?, ?, 'locked', ?, ?)
             ON DUPLICATE KEY UPDATE local_date = VALUES(local_date)"
        )->execute([$kidId, $day, $now, $now]);
        $stmt = $this->pdo->prepare('SELECT * FROM kid_days WHERE kid_id = ? AND local_date = ? FOR UPDATE');
        $stmt->execute([$kidId, $day]);
        return $stmt->fetch();
    }

    /** Record a status (manual overrides pass $overrideBy). */
    public function saveStatus(array $row, string $status, ?int $overrideBy = null, ?string $note = null): void
    {
        $now = $this->clock->nowSql();
        if ($status === $row['status'] && $overrideBy === null) {
            return;
        }
        $this->pdo->prepare(
            'UPDATE kid_days SET status = ?, unlocked_at = ?, forfeited_at = ?,
               override_by_parent_id = ?, override_note = ?, overridden_at = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $status,
            $status === 'unlocked' ? ($row['status'] === 'unlocked' ? $row['unlocked_at'] : $now) : $row['unlocked_at'],
            $status === 'forfeited' ? ($row['status'] === 'forfeited' ? $row['forfeited_at'] : $now) : $row['forfeited_at'],
            $overrideBy ?? $row['override_by_parent_id'], $overrideBy !== null ? $note : $row['override_note'],
            $overrideBy !== null ? $now : $row['overridden_at'], $now, $row['id'],
        ]);
    }

    // ---- money (the only place pending/spending moves happen) -----------------------------------------

    /**
     * Unlock: the day's pending earnings become spendable. Phase 4 will split Savings/Giving off the top HERE,
     * before the remainder goes to Spending — keep this the single place that happens.
     */
    public function moveToSpending(int $householdId, int $kidId, string $day, ?int $parentId = null): void
    {
        foreach ($this->pendingNet($kidId, $day) as $currency => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $ref = ['local_date' => $day] + $this->by($parentId);
            $this->ledger->append($householdId, $kidId, $currency, 'pending', 'unlock', -$amount, $ref);
            $this->ledger->append($householdId, $kidId, $currency, 'spending', 'unlock', $amount, $ref);
        }
    }

    /** Forfeit: cancel the day's pending earnings. They never reach Spending. */
    public function forfeitPending(int $householdId, int $kidId, string $day, ?int $parentId = null): void
    {
        foreach ($this->pendingNet($kidId, $day) as $currency => $amount) {
            if ($amount > 0) {
                $this->ledger->append($householdId, $kidId, $currency, 'pending', 'forfeit', -$amount,
                    ['local_date' => $day, 'note' => 'Day forfeited'] + $this->by($parentId));
            }
        }
    }

    /** Undo an earlier forfeit with an offsetting entry, so the money can be unlocked. */
    public function reverseForfeit(int $householdId, int $kidId, string $day, ?int $parentId = null): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT currency, SUM(amount) AS total FROM ledger_entries
             WHERE kid_id = ? AND local_date = ? AND bucket = 'pending' AND entry_type = 'forfeit' GROUP BY currency"
        );
        $stmt->execute([$kidId, $day]);
        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['total'] < 0) {
                $this->ledger->append($householdId, $kidId, $r['currency'], 'pending', 'forfeit', -(int) $r['total'],
                    ['local_date' => $day, 'note' => 'Forfeit reversed'] + $this->by($parentId));
            }
        }
    }

    /** Forfeiting a day that already unlocked takes its money back out of Spending (refused if already spent). */
    public function clawBackSpending(int $householdId, int $kidId, string $day, int $parentId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT currency, SUM(amount) AS total FROM ledger_entries
             WHERE kid_id = ? AND local_date = ? AND bucket = 'spending' GROUP BY currency"
        );
        $stmt->execute([$kidId, $day]);
        foreach ($stmt->fetchAll() as $r) {
            $amount = (int) $r['total'];
            if ($amount <= 0) {
                continue;
            }
            if ($this->ledger->balance($kidId, $r['currency'], 'spending') < $amount) {
                throw new ApiException(409, "That day's earnings have already been spent, so it can't be forfeited.");
            }
            $this->ledger->append($householdId, $kidId, $r['currency'], 'spending', 'forfeit', -$amount,
                ['local_date' => $day, 'note' => 'Day forfeited'] + $this->by($parentId));
        }
    }

    /** @return array<string,int> currency => net pending amount for that earning day */
    public function pendingNet(int $kidId, string $day): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT currency, SUM(amount) AS total FROM ledger_entries WHERE kid_id = ? AND bucket = 'pending' AND local_date = ? GROUP BY currency"
        );
        $stmt->execute([$kidId, $day]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['currency']] = (int) $r['total'];
        }
        return $out;
    }

    /** @return array{created_by_type:string, created_by_id?:int} */
    private function by(?int $parentId): array
    {
        return $parentId !== null ? ['created_by_type' => 'parent', 'created_by_id' => $parentId] : ['created_by_type' => 'system'];
    }
}
