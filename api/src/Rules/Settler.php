<?php
declare(strict_types=1);

namespace App\Rules;

use App\Chores\ChoreService;
use App\Chores\InstanceCreator;
use App\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * End-of-day settlement, on demand. `households.settled_through` is the last household day that has been fully
 * settled. Any request for a household settles every day that has ended since then, oldest first, one day per
 * transaction. The household row is locked while a day is settled, so two requests at once can't both do the work:
 * the second waits, sees the day is done, and moves on. Settling is also idempotent by itself.
 *
 * Settling a day: create any instances nobody opened the app for, mark undone repeating chores missed (or carry
 * the most recent one over), mark expired one-time chores missed, then settle each kid's day (forfeit, wait, or unlock).
 */
final class Settler
{
    /** Never look back further than this, so a long-idle household can't make one request very slow. */
    private const MAX_DAYS_BACK = 366;

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private ChoreService $chores,
        private InstanceCreator $creator,
        private DayRules $rules,
    ) {}

    /**
     * Settle every ended day. If it fails, the failure is remembered on the household (parents see a warning on
     * Home) and the error is rethrown; the next settle that succeeds clears the warning.
     * @return int how many days this call settled
     */
    public function settleHousehold(int $householdId): int
    {
        try {
            $settled = $this->run($householdId);
        } catch (\Throwable $e) {
            $this->markFailed($householdId);
            throw $e;
        }
        // Everything that has ended is settled (this call or an earlier one): any earlier failure is resolved.
        $this->pdo->prepare('UPDATE households SET settle_failed_at = NULL WHERE id = ? AND settle_failed_at IS NOT NULL')->execute([$householdId]);
        return $settled;
    }

    private function markFailed(int $householdId): void
    {
        try {
            $this->pdo->prepare('UPDATE households SET settle_failed_at = ? WHERE id = ?')->execute([$this->clock->nowSql(), $householdId]);
        } catch (\Throwable) {
            // Recording the warning must never hide the original problem.
        }
    }

    private function run(int $householdId): int
    {
        $settled = 0;
        while (true) {
            if ($this->nextDay($householdId, false) === null) {
                return $settled;
            }
            $this->pdo->beginTransaction();
            try {
                $day = $this->nextDay($householdId, true); // re-check while holding the lock
                if ($day !== null) {
                    $this->settleDay($householdId, $day);
                    $this->pdo->prepare('UPDATE households SET settled_through = ? WHERE id = ?')->execute([$day, $householdId]);
                    $settled++;
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    /** Settle every household (for scripts/settle.php and a possible cron job). @return array<int,int> household id => days */
    public function settleAll(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id FROM households ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = $this->settleHousehold((int) $id);
        }
        return $out;
    }

    /** The next unsettled day that has ended, or null if there is none. */
    private function nextDay(int $householdId, bool $lock): ?string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM households WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$householdId]);
        $h = $stmt->fetch();
        if (!$h) {
            return null;
        }
        $time = \App\HouseholdTime::forHousehold($h, $this->clock);
        $today = $time->today();
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $next = $h['settled_through'] !== null
            ? (new DateTimeImmutable($h['settled_through']))->modify('+1 day')->format('Y-m-d')
            : $time->dayFor(new DateTimeImmutable($h['created_at'], new DateTimeZone('UTC'))); // never before the household existed
        $floor = (new DateTimeImmutable($today))->modify('-' . self::MAX_DAYS_BACK . ' days')->format('Y-m-d');
        if ($next < $floor) {
            $next = $floor;
        }
        return $next <= $yesterday ? $next : null;
    }

    private function settleDay(int $householdId, string $day): void
    {
        $kids = $this->pdo->prepare('SELECT id FROM kids WHERE household_id = ? AND active = 1 ORDER BY id');
        $kids->execute([$householdId]);
        foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $kidId) {
            $kidId = (int) $kidId;
            $this->creator->ensureDay($householdId, $kidId, $day);
            $this->closeOut($kidId, $day);
            $this->rules->reconcile($householdId, $kidId, $day);
        }
    }

    /** What happens to chores that were not reported by the end of the day. */
    private function closeOut(int $kidId, string $day): void
    {
        $now = $this->clock->nowSql();
        $markMissed = $this->pdo->prepare("UPDATE chore_instances SET status = 'missed', updated_at = ? WHERE id = ? AND status IN ('assigned', 'sent_back')");

        // Repeating chores whose day just ended.
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.slot_id, c.overdue_behavior FROM chore_instances i JOIN chores c ON c.id = i.chore_id
             WHERE i.kid_id = ? AND i.local_date = ? AND c.schedule_type = 'daily' AND i.status IN ('assigned', 'sent_back')"
        );
        $stmt->execute([$kidId, $day]);
        foreach ($stmt->fetchAll() as $i) {
            if ($i['overdue_behavior'] === 'expire') {
                $markMissed->execute([$now, $i['id']]);
                continue;
            }
            // Carries over: this is now the one overdue copy for the slot; older undone ones are missed (no stacking).
            $older = $this->pdo->prepare(
                "SELECT id FROM chore_instances WHERE slot_id = ? AND kid_id = ? AND local_date < ? AND status IN ('assigned', 'sent_back')"
            );
            $older->execute([$i['slot_id'], $kidId, $day]);
            foreach ($older->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                $markMissed->execute([$now, $oldId]);
            }
        }

        // One-time chores set to expire: missed once their due date (or creation day) has passed.
        $stmt = $this->pdo->prepare(
            "SELECT i.id FROM chore_instances i JOIN chores c ON c.id = i.chore_id
             WHERE i.kid_id = ? AND c.schedule_type = 'one_off' AND c.overdue_behavior = 'expire'
               AND i.status IN ('assigned', 'sent_back') AND COALESCE(c.due_date, i.local_date) <= ?"
        );
        $stmt->execute([$kidId, $day]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $markMissed->execute([$now, $id]);
        }
    }
}
