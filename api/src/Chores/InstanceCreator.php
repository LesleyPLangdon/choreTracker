<?php
declare(strict_types=1);

namespace App\Chores;

use App\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Creates the instances that belong on a household day. One place, used both for "today" (when a screen loads)
 * and for past days nobody opened (when settling). Idempotent: the unique key (slot + date) makes repeats no-ops.
 *
 * Only chores and slots that existed on that day are used, so a chore added on Thursday never produces
 * Tuesday instances, and settling can never punish a kid for something that wasn't assigned yet.
 */
final class InstanceCreator
{
    public function __construct(private PDO $pdo, private Clock $clock, private ChoreService $chores) {}

    public function ensureDay(int $householdId, int $kidId, string $day): void
    {
        $time = $this->chores->householdTime($householdId);
        [, $dayEnd] = $time->dayBoundsUtc($day);
        $bit = 1 << (\App\HouseholdTime::isoWeekday($day) - 1);
        $now = $this->clock->nowSql();

        // Repeating chores: one instance per slot, if the weekday is scheduled.
        // required_for_date: the instance gates its own day, but only from the chore's required_from day on.
        $this->pdo->prepare(
            "INSERT INTO chore_instances (household_id, chore_id, slot_id, kid_id, local_date, required_for_date, status, value, currency, created_at, updated_at)
             SELECT c.household_id, c.id, s.id, c.kid_id, ?,
                    CASE WHEN c.is_required = 1 AND (c.required_from IS NULL OR c.required_from <= ?) THEN ? ELSE NULL END,
                    'assigned', c.value, c.currency, ?, ?
             FROM chores c JOIN chore_slots s ON s.chore_id = c.id
             WHERE c.kid_id = ? AND c.schedule_type = 'daily' AND (c.days_of_week & ?) <> 0
               AND c.created_at < ? AND (c.active = 1 OR c.updated_at >= ?)
               AND (s.created_at IS NULL OR s.created_at < ?) AND (s.active = 1 OR s.deactivated_at >= ?)
             ON DUPLICATE KEY UPDATE local_date = VALUES(local_date)"
        )->execute([$day, $day, $day, $now, $now, $kidId, $bit, $dayEnd, $dayEnd, $dayEnd, $dayEnd]);

        // One-time chores: a single instance per slot, ever, dated the day the chore was created.
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS chore_id, c.household_id, c.value, c.currency, c.created_at, c.is_required, c.required_from, c.due_date, s.id AS slot_id
             FROM chores c JOIN chore_slots s ON s.chore_id = c.id
             WHERE c.kid_id = ? AND c.schedule_type = 'one_off'
               AND c.created_at < ? AND (c.active = 1 OR c.updated_at >= ?)
               AND (s.created_at IS NULL OR s.created_at < ?) AND (s.active = 1 OR s.deactivated_at >= ?)
               AND NOT EXISTS (SELECT 1 FROM chore_instances i WHERE i.slot_id = s.id)"
        );
        $stmt->execute([$kidId, $dayEnd, $dayEnd, $dayEnd, $dayEnd]);
        $insert = $this->pdo->prepare(
            "INSERT INTO chore_instances (household_id, chore_id, slot_id, kid_id, local_date, required_for_date, status, value, currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'assigned', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE local_date = VALUES(local_date)"
        );
        foreach ($stmt->fetchAll() as $c) {
            $createdDay = $time->dayFor(new DateTimeImmutable($c['created_at'], new DateTimeZone('UTC')));
            // A required one-time chore only gates its due date (or its creation day), and never before required_from.
            $gate = null;
            if ((int) $c['is_required'] === 1) {
                $gate = max($c['due_date'] ?? $createdDay, $c['required_from'] ?? $createdDay);
            }
            $insert->execute([$c['household_id'], $c['chore_id'], $c['slot_id'], $kidId, $createdDay, $gate, $c['value'], $c['currency'], $now, $now]);
        }
    }

    /**
     * Instance for one slot on one past day (used by back-fill): returns its row, creating it if the chore and
     * slot existed and were scheduled then. Returns null if that chore/slot does not belong on that day.
     */
    public function findOrCreateForSlot(int $householdId, int $kidId, int $slotId, string $day): ?array
    {
        $this->ensureDay($householdId, $kidId, $day);
        $stmt = $this->pdo->prepare(
            "SELECT i.* FROM chore_instances i JOIN chores c ON c.id = i.chore_id
             WHERE i.slot_id = ? AND i.kid_id = ? AND i.household_id = ?
               AND ((c.schedule_type = 'daily' AND i.local_date = ?) OR c.schedule_type = 'one_off')"
        );
        $stmt->execute([$slotId, $kidId, $householdId, $day]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
