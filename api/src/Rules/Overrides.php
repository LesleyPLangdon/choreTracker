<?php
declare(strict_types=1);

namespace App\Rules;

use App\Chores\ChoreService;
use App\Chores\InstanceCreator;
use App\Chores\InstanceService;
use App\Clock;
use App\Http\ApiException;
use App\Ledger;
use App\HouseholdTime;
use App\Money\Wallet;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Parent powers over past days (up to 7 days back): back-fill, unlock, forfeit, undo an approval, clear a late flag,
 * plus balance adjustments and the per-kid history. Every action is logged in day_events (who and when).
 * Money only ever changes through appended ledger entries.
 */
final class Overrides
{
    public const DAYS_BACK = 7;

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private Ledger $ledger,
        private ChoreService $chores,
        private InstanceCreator $creator,
        private InstanceService $instances,
        private DayRules $rules,
        private Wallet $wallet,
    ) {}

    // ---- back-fill -----------------------------------------------------------------------------------

    /** "It was done, we just didn't tap it." Creates the instance if needed, approves it, writes its earning, re-settles the day. */
    public function backFill(int $householdId, int $parentId, int $kidId, int $slotId, string $day, ?string $note): array
    {
        $time = $this->chores->householdTime($householdId);
        $today = $time->today();
        $this->requireDay($day, $today, false);
        $this->requireKid($householdId, $kidId);

        return $this->inTransaction(function () use ($householdId, $parentId, $kidId, $slotId, $day, $note, $time) {
            $i = $this->creator->findOrCreateForSlot($householdId, $kidId, $slotId, $day);
            if (!$i) {
                throw new ApiException(422, "That chore wasn't scheduled for that day.");
            }
            $lock = $this->pdo->prepare('SELECT * FROM chore_instances WHERE id = ? FOR UPDATE');
            $lock->execute([$i['id']]);
            $i = $lock->fetch();
            if ($i['status'] === 'approved') {
                throw new ApiException(409, 'That one is already approved.');
            }
            $now = $this->clock->nowSql();
            $this->pdo->prepare(
                "UPDATE chore_instances SET status = 'approved', reported_at = ?, is_late = 0, is_back_filled = 1,
                   approved_by_parent_id = ?, approved_at = ?, send_back_note = NULL, updated_at = ? WHERE id = ?"
            )->execute([$now, $parentId, $now, $now, $i['id']]);
            $this->earnFor((int) $i['id'], $time, $parentId);
            $this->log($householdId, $kidId, $i['local_date'], (int) $i['id'], 'back_fill', $parentId, $note);
            // Re-settle: back-filling the last missing required chore on a forfeited day unlocks it.
            $this->instances->reconcileFor((int) $i['id'], $time, true);
            return $this->instances->single((int) $i['id'], $time);
        });
    }

    // ---- day overrides -----------------------------------------------------------------------------

    /** Unlock a forfeited, waiting, or still-locked day: forfeits are reversed and the earnings move to Spending. */
    public function unlockDay(int $householdId, int $parentId, int $kidId, string $day, ?string $note): array
    {
        $time = $this->chores->householdTime($householdId);
        $this->requireDay($day, $time->today(), true);
        $this->requireKid($householdId, $kidId);
        return $this->inTransaction(function () use ($householdId, $parentId, $kidId, $day, $note) {
            $row = $this->rules->lockDay($kidId, $day);
            if ($row['status'] === 'unlocked') {
                throw new ApiException(409, 'That day is already unlocked.');
            }
            $this->rules->saveStatus($row, 'unlocked', $parentId, $note);
            $this->rules->reverseForfeit($householdId, $kidId, $day, $parentId);
            $this->rules->moveToSpending($householdId, $kidId, $day, $parentId);
            $this->log($householdId, $kidId, $day, null, 'unlock', $parentId, $note);
            return $this->dayStatus($kidId, $day);
        });
    }

    /** Forfeit a day: pending earnings are cancelled (and an unlocked day's money comes back out of Spending). */
    public function forfeitDay(int $householdId, int $parentId, int $kidId, string $day, ?string $note): array
    {
        $time = $this->chores->householdTime($householdId);
        $this->requireDay($day, $time->today(), true);
        $this->requireKid($householdId, $kidId);
        return $this->inTransaction(function () use ($householdId, $parentId, $kidId, $day, $note) {
            $row = $this->rules->lockDay($kidId, $day);
            if ($row['status'] === 'forfeited') {
                throw new ApiException(409, 'That day is already forfeited.');
            }
            $this->rules->clawBackSpending($householdId, $kidId, $day, $parentId);
            $this->rules->forfeitPending($householdId, $kidId, $day, $parentId);
            $this->rules->saveStatus($row, 'forfeited', $parentId, $note);
            $this->log($householdId, $kidId, $day, null, 'forfeit', $parentId, $note);
            return $this->dayStatus($kidId, $day);
        });
    }

    /**
     * Undo an approval. The earning is offset by negative entries (from wherever the money now sits); the chore goes
     * back to "waiting for approval" and the day is re-settled. Refused if the money has already been spent.
     */
    public function undoApproval(int $householdId, int $parentId, int $instanceId, ?string $note): array
    {
        $time = $this->chores->householdTime($householdId);
        return $this->inTransaction(function () use ($householdId, $parentId, $instanceId, $note, $time) {
            $i = $this->lockInstance($householdId, $instanceId);
            $this->requireDay($i['local_date'], $time->today(), true);
            if ($i['status'] !== 'approved') {
                throw new ApiException(409, "That chore isn't approved.");
            }
            $day = $this->instances->earningDay($instanceId, $time);
            $kidId = (int) $i['kid_id'];
            // How much this approval earned, per currency (earnings net of any earlier undo).
            $stmt = $this->pdo->prepare("SELECT currency, SUM(amount) AS total FROM ledger_entries WHERE chore_instance_id = ? AND entry_type = 'earning' GROUP BY currency");
            $stmt->execute([$instanceId]);
            foreach ($stmt->fetchAll() as $r) {
                $amount = (int) $r['total'];
                if ($amount > 0) {
                    $this->takeBack($householdId, $parentId, $kidId, $instanceId, $day, $r['currency'], $amount);
                }
            }
            $now = $this->clock->nowSql();
            $this->pdo->prepare(
                "UPDATE chore_instances SET status = 'reported_done', approved_by_parent_id = NULL, approved_at = NULL, updated_at = ? WHERE id = ?"
            )->execute([$now, $instanceId]);
            $this->log($householdId, (int) $i['kid_id'], $i['local_date'], $instanceId, 'undo_approval', $parentId, $note);
            $this->instances->reconcileFor($instanceId, $time);
            return $this->instances->single($instanceId, $time);
        });
    }

    /**
     * Offset an earning wherever the day's money currently is: still pending (locked); forfeited (the forfeit is offset
     * too, so the day's totals stay consistent); or already unlocked, in which case it is taken back from Spending,
     * Savings, and Giving in the proportions the day was split (refused if any of it has been spent or moved on).
     */
    private function takeBack(int $householdId, int $parentId, int $kidId, int $instanceId, string $day, string $currency, int $amount): void
    {
        $batch = $this->ledger->newBatch();
        $ref = ['chore_instance_id' => $instanceId, 'local_date' => $day, 'note' => 'Approval undone', 'created_by_type' => 'parent',
            'created_by_id' => $parentId, 'batch' => $batch, 'action' => 'earning_undone'];
        $pending = $this->rules->pendingNet($kidId, $day)[$currency] ?? 0;
        if ($pending >= $amount) {
            $this->ledger->append($householdId, $kidId, $currency, 'pending', 'earning', -$amount, $ref);
            return;
        }
        $forfeited = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE kid_id = ? AND local_date = ? AND currency = ? AND bucket = 'pending' AND entry_type = 'forfeit'");
        $forfeited->execute([$kidId, $day, $currency]);
        if (-(int) $forfeited->fetchColumn() >= $amount) {
            $this->ledger->append($householdId, $kidId, $currency, 'pending', 'earning', -$amount, $ref);
            $this->ledger->append($householdId, $kidId, $currency, 'pending', 'forfeit', $amount, ['local_date' => $day, 'note' => 'Forfeit offset: approval undone',
                'created_by_type' => 'parent', 'created_by_id' => $parentId, 'batch' => $batch, 'action' => 'earning_undone']);
            return;
        }
        // Already unlocked: work out how much of this amount went to each bucket when the day was split.
        $sums = $this->pdo->prepare(
            "SELECT bucket, entry_type, SUM(amount) AS total FROM ledger_entries
             WHERE kid_id = ? AND local_date = ? AND currency = ? AND bucket IN ('pending', 'savings', 'giving') AND entry_type IN ('unlock', 'bucket_split')
             GROUP BY bucket, entry_type"
        );
        $sums->execute([$kidId, $day, $currency]);
        $moved = 0;
        $split = ['savings' => 0, 'giving' => 0];
        foreach ($sums->fetchAll() as $r) {
            if ($r['bucket'] === 'pending') {
                $moved += -(int) $r['total'];
            } else {
                $split[$r['bucket']] += (int) $r['total'];
            }
        }
        $take = ['savings' => 0, 'giving' => 0];
        if ($moved > 0) {
            foreach (['savings', 'giving'] as $bucket) {
                $take[$bucket] = min($amount, intdiv(max(0, $split[$bucket]) * $amount, $moved));
            }
        }
        $take['spending'] = $amount - $take['savings'] - $take['giving'];
        foreach ($take as $bucket => $part) {
            if ($part > 0 && $this->ledger->balance($kidId, $currency, $bucket) < $part) {
                throw new ApiException(409, "That money has already been spent or moved, so the approval can't be undone.");
            }
        }
        foreach ($take as $bucket => $part) {
            if ($part > 0) {
                $this->ledger->append($householdId, $kidId, $currency, $bucket, 'earning', -$part, $ref);
            }
        }
    }

    public function clearLate(int $householdId, int $parentId, int $instanceId, ?string $note): array
    {
        $time = $this->chores->householdTime($householdId);
        return $this->inTransaction(function () use ($householdId, $parentId, $instanceId, $note, $time) {
            $i = $this->lockInstance($householdId, $instanceId);
            $this->requireDay($i['local_date'], $time->today(), true);
            if ((int) $i['is_late'] !== 1) {
                throw new ApiException(409, "That chore isn't marked late.");
            }
            $this->pdo->prepare('UPDATE chore_instances SET is_late = 0, updated_at = ? WHERE id = ?')->execute([$this->clock->nowSql(), $instanceId]);
            $this->log($householdId, (int) $i['kid_id'], $i['local_date'], $instanceId, 'clear_late', $parentId, $note);
            return $this->instances->single($instanceId, $time);
        });
    }

    // ---- balance adjustment ----------------------------------------------------------------------------

    /** Add or subtract from Spending, Savings, or Giving, with a note. Never below zero (see Wallet). */
    public function adjustBalance(int $householdId, int $parentId, int $kidId, string $currency, int $amount, string $note, string $bucket = 'spending'): array
    {
        $this->requireKid($householdId, $kidId);
        return $this->wallet->adjust($householdId, $parentId, $kidId, $bucket, $currency, $amount, $note);
    }

    // ---- history --------------------------------------------------------------------------------------

    /**
     * Today and the previous 7 household days for one kid: status, chores, earnings, and who changed what.
     */
    public function history(int $householdId, int $kidId): array
    {
        $this->requireKid($householdId, $kidId);
        $time = $this->chores->householdTime($householdId);
        $today = $time->today();
        $created = $this->pdo->prepare('SELECT created_at FROM households WHERE id = ?');
        $created->execute([$householdId]);
        $firstDay = $time->dayFor(new DateTimeImmutable($created->fetchColumn(), new DateTimeZone('UTC')));
        $days = [];
        for ($n = 0; $n <= self::DAYS_BACK; $n++) {
            $day = (new DateTimeImmutable($today))->modify("-$n days")->format('Y-m-d');
            if ($day < $firstDay) {
                break; // nothing existed before the household did
            }
            $days[] = $this->dayJson($householdId, $kidId, $day, $today, $time);
        }
        $adj = $this->pdo->prepare(
            "SELECT e.currency, e.amount, e.note, e.created_at, p.display_name FROM ledger_entries e
             LEFT JOIN parents p ON e.created_by_type = 'parent' AND p.id = e.created_by_id
             WHERE e.kid_id = ? AND e.entry_type = 'parent_adjustment' ORDER BY e.id DESC LIMIT 10"
        );
        $adj->execute([$kidId]);
        return [
            'today' => $today,
            'days' => $days,
            'spending' => $this->instances->spending($kidId),
            'adjustments' => array_map(fn ($a) => [
                'currency' => $a['currency'], 'amount' => (int) $a['amount'], 'note' => $a['note'],
                'at_local' => $time->utcToLocal($a['created_at']), 'by' => $a['display_name'],
            ], $adj->fetchAll()),
        ];
    }

    private function dayJson(int $householdId, int $kidId, string $day, string $today, HouseholdTime $time): array
    {
        [$ds, $de] = $time->dayBoundsUtc($day);
        $kd = $this->pdo->prepare('SELECT status, override_by_parent_id FROM kid_days WHERE kid_id = ? AND local_date = ?');
        $kd->execute([$kidId, $day]);
        $kdRow = $kd->fetch();

        $stmt = $this->pdo->prepare(
            "SELECT i.*, c.title, c.schedule_type, c.due_date, c.auto_approve, c.overdue_behavior, c.active AS chore_active,
                    s.label, s.available_from, s.due_by, s.sort_order,
                    (SELECT COUNT(*) FROM chore_slots s2 WHERE s2.chore_id = c.id AND s2.active = 1) AS slot_count
             FROM chore_instances i JOIN chores c ON c.id = i.chore_id JOIN chore_slots s ON s.id = i.slot_id
             WHERE i.kid_id = ? AND (i.local_date = ? OR (i.reported_at >= ? AND i.reported_at < ?))
             ORDER BY c.title, s.sort_order, i.id"
        );
        $stmt->execute([$kidId, $day, $ds, $de]);
        $canEdit = $day >= (new DateTimeImmutable($today))->modify('-' . self::DAYS_BACK . ' days')->format('Y-m-d');
        $chores = [];
        foreach ($stmt->fetchAll() as $r) {
            $row = $this->instances->rowFromRecord($r, $time, $today);
            $row['for_date'] = $r['local_date'];
            $row['required'] = $r['required_for_date'] !== null; // required on its own day (not just today)
            $row['back_filled'] = (bool) $r['is_back_filled'];
            $row['can_back_fill'] = $canEdit && $day < $today && $r['local_date'] === $day && $r['status'] !== 'approved';
            $row['can_undo_approval'] = $canEdit && $r['status'] === 'approved';
            $row['can_clear_late'] = $canEdit && (bool) $r['is_late'];
            $chores[] = $row;
        }

        // Chores scheduled that day that have no instance yet (can happen for a slot added later) are not shown;
        // settlement creates every instance that belonged on an ended day.

        $earned = $this->pdo->prepare(
            "SELECT currency, SUM(amount) AS total FROM ledger_entries WHERE kid_id = ? AND entry_type = 'earning' AND local_date = ? GROUP BY currency"
        );
        $earned->execute([$kidId, $day]);
        $earnedOut = ['points' => 0, 'money' => 0];
        foreach ($earned->fetchAll() as $e) {
            $earnedOut[$e['currency']] = (int) $e['total'];
        }
        $pending = $this->rules->pendingNet($kidId, $day);

        $ev = $this->pdo->prepare(
            "SELECT e.action, e.note, e.created_at, e.instance_id, p.display_name FROM day_events e
             LEFT JOIN parents p ON p.id = e.parent_id WHERE e.kid_id = ? AND e.local_date = ? ORDER BY e.id"
        );
        $ev->execute([$kidId, $day]);

        $status = $kdRow['status'] ?? ($day === $today ? 'locked' : 'unlocked');
        return [
            'date' => $day,
            'is_today' => $day === $today,
            'status' => $status,
            'manual' => $kdRow && $kdRow['override_by_parent_id'] !== null,
            'can_unlock' => $canEdit && $status !== 'unlocked',
            'can_forfeit' => $canEdit && $status !== 'forfeited',
            'chores' => $chores,
            'earned' => $earnedOut,
            'locked' => ['points' => max(0, $pending['points'] ?? 0), 'money' => max(0, $pending['money'] ?? 0)],
            'events' => array_map(fn ($e) => [
                'action' => $e['action'], 'note' => $e['note'], 'by' => $e['display_name'],
                'at_local' => $time->utcToLocal($e['created_at']), 'instance_id' => $e['instance_id'] !== null ? (int) $e['instance_id'] : null,
            ], $ev->fetchAll()),
        ];
    }

    // ---- internals ------------------------------------------------------------------------------------

    private function dayStatus(int $kidId, string $day): array
    {
        $stmt = $this->pdo->prepare('SELECT status FROM kid_days WHERE kid_id = ? AND local_date = ?');
        $stmt->execute([$kidId, $day]);
        return ['date' => $day, 'status' => $stmt->fetchColumn()];
    }

    /** Days a parent may change: today and up to 7 days back. Back-fill is for past days only. */
    private function requireDay(string $day, string $today, bool $includeToday): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$parsed || $parsed->format('Y-m-d') !== $day) {
            throw new ApiException(422, 'Choose a valid date.');
        }
        $oldest = (new DateTimeImmutable($today))->modify('-' . self::DAYS_BACK . ' days')->format('Y-m-d');
        if ($day < $oldest) {
            throw new ApiException(422, 'You can change days up to ' . self::DAYS_BACK . ' days back.');
        }
        if ($day > $today || ($day === $today && !$includeToday)) {
            throw new ApiException(422, $day === $today ? 'For today, use "Mark done".' : "That day hasn't happened yet.");
        }
    }

    private function requireKid(int $householdId, int $kidId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM kids WHERE id = ? AND household_id = ?');
        $stmt->execute([$kidId, $householdId]);
        if (!$stmt->fetchColumn()) {
            throw new ApiException(404, 'Kid not found.');
        }
    }

    private function lockInstance(int $householdId, int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chore_instances WHERE id = ? AND household_id = ? FOR UPDATE');
        $stmt->execute([$instanceId, $householdId]);
        $i = $stmt->fetch();
        if (!$i) {
            throw new ApiException(404, 'Chore not found.');
        }
        return $i;
    }

    private function earnFor(int $instanceId, HouseholdTime $time, int $parentId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, c.schedule_type FROM chore_instances i JOIN chores c ON c.id = i.chore_id WHERE i.id = ?'
        );
        $stmt->execute([$instanceId]);
        $this->instances->earnPublic($stmt->fetch(), $time, $this->clock->nowSql(), $parentId);
    }

    private function log(int $householdId, int $kidId, string $day, ?int $instanceId, string $action, int $parentId, ?string $note): void
    {
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 200) {
            throw new ApiException(422, 'Keep the note under 200 characters.');
        }
        $this->pdo->prepare(
            'INSERT INTO day_events (household_id, kid_id, local_date, instance_id, action, parent_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$householdId, $kidId, $day, $instanceId, $action, $parentId, $note !== '' ? $note : null, $this->clock->nowSql()]);
    }

    private function inTransaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
