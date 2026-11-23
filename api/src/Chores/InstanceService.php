<?php
declare(strict_types=1);

namespace App\Chores;

use App\Clock;
use App\Http\ApiException;
use App\HouseholdTime;
use App\Ledger;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Daily chore instances: creating them on demand, and the done / undo / approve / send back flow.
 * "Today" always comes from the household's HouseholdTime (time zone + end-of-day), never from the server clock.
 */
final class InstanceService
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private Ledger $ledger,
        private ChoreService $chores,
    ) {}

    // ---- creating today's instances ---------------------------------------------------------------

    /**
     * Make sure every chore slot that belongs on this kid's list today has an instance. Idempotent and safe
     * when two requests run at once: the unique key (slot + date) makes a second insert a no-op.
     */
    public function ensureToday(int $householdId, int $kidId): void
    {
        $time = $this->chores->householdTime($householdId);
        $today = $time->today();
        $bit = 1 << (HouseholdTime::isoWeekday($today) - 1);
        $now = $this->clock->nowSql();

        // Repeating chores: one instance per slot for today, if today is a scheduled day.
        $this->pdo->prepare(
            "INSERT INTO chore_instances (household_id, chore_id, slot_id, kid_id, local_date, status, value, currency, created_at, updated_at)
             SELECT c.household_id, c.id, s.id, c.kid_id, ?, 'assigned', c.value, c.currency, ?, ?
             FROM chores c JOIN chore_slots s ON s.chore_id = c.id AND s.active = 1
             WHERE c.kid_id = ? AND c.active = 1 AND c.schedule_type = 'daily' AND (c.days_of_week & ?) <> 0
             ON DUPLICATE KEY UPDATE local_date = VALUES(local_date)"
        )->execute([$today, $now, $now, $kidId, $bit]);

        // One-time chores: a single instance per slot, ever, dated the day the chore was created.
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS chore_id, c.household_id, c.value, c.currency, c.created_at, s.id AS slot_id
             FROM chores c JOIN chore_slots s ON s.chore_id = c.id AND s.active = 1
             WHERE c.kid_id = ? AND c.active = 1 AND c.schedule_type = 'one_off'
               AND NOT EXISTS (SELECT 1 FROM chore_instances i WHERE i.slot_id = s.id)"
        );
        $stmt->execute([$kidId]);
        $insert = $this->pdo->prepare(
            "INSERT INTO chore_instances (household_id, chore_id, slot_id, kid_id, local_date, status, value, currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'assigned', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE local_date = VALUES(local_date)"
        );
        foreach ($stmt->fetchAll() as $c) {
            $createdDay = $time->dayFor(new DateTimeImmutable($c['created_at'], new DateTimeZone('UTC')));
            $insert->execute([$c['household_id'], $c['chore_id'], $c['slot_id'], $kidId, $createdDay, $c['value'], $c['currency'], $now, $now]);
        }
    }

    // ---- reading ------------------------------------------------------------------------------------

    /**
     * A kid's day: today's repeating chores plus any open one-time chores.
     * @return array{today:string, chores:list<array>, earned_today:array{points:int,money:int}}
     */
    public function dayFor(int $householdId, int $kidId): array
    {
        $this->ensureToday($householdId, $kidId);
        $time = $this->chores->householdTime($householdId);
        $today = $time->today();
        [$dayStart, $dayEnd] = $time->dayBoundsUtc($today);

        $stmt = $this->pdo->prepare(
            "SELECT i.*, c.title, c.schedule_type, c.due_date, c.auto_approve, c.active AS chore_active,
                    s.label, s.available_from, s.due_by, s.sort_order,
                    (SELECT COUNT(*) FROM chore_slots s2 WHERE s2.chore_id = c.id AND s2.active = 1) AS slot_count
             FROM chore_instances i
             JOIN chores c ON c.id = i.chore_id
             JOIN chore_slots s ON s.id = i.slot_id
             WHERE i.kid_id = ?
               AND (c.active = 1 OR i.status IN ('reported_done', 'approved'))
               AND ((c.schedule_type = 'daily' AND i.local_date = ?)
                 OR (c.schedule_type = 'one_off' AND (
                       (i.status IN ('assigned', 'sent_back') AND c.active = 1)
                       OR i.status = 'reported_done'
                       OR (i.status = 'approved' AND i.reported_at >= ? AND i.reported_at < ?))))
             ORDER BY c.title, s.sort_order, i.id"
        );
        $stmt->execute([$kidId, $today, $dayStart, $dayEnd]);
        $rows = array_map(fn ($r) => $this->rowJson($r, $time, $today), $stmt->fetchAll());

        return ['today' => $today, 'chores' => $rows, 'earned_today' => $this->earnedToday($kidId, $today)];
    }

    /** Totals of earnings recorded for a day, per currency (whole points; money in cents). */
    public function earnedToday(int $kidId, string $day): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT currency, SUM(amount) AS total FROM ledger_entries
             WHERE kid_id = ? AND entry_type = 'earning' AND local_date = ? GROUP BY currency"
        );
        $stmt->execute([$kidId, $day]);
        $out = ['points' => 0, 'money' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['currency']] = (int) $r['total'];
        }
        return $out;
    }

    /** Everything waiting for a parent. Items are typed so later phases can add goals, rewards, and challenges. */
    public function approvals(int $householdId): array
    {
        $time = $this->chores->householdTime($householdId);
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.value, i.currency, i.reported_at, i.is_late, c.title, s.label, k.id AS kid_id, k.nickname, k.avatar,
                    (SELECT COUNT(*) FROM chore_slots s2 WHERE s2.chore_id = c.id AND s2.active = 1) AS slot_count
             FROM chore_instances i
             JOIN chores c ON c.id = i.chore_id
             JOIN chore_slots s ON s.id = i.slot_id
             JOIN kids k ON k.id = i.kid_id
             WHERE i.household_id = ? AND i.status = 'reported_done'
             ORDER BY i.reported_at DESC, i.id DESC"
        );
        $stmt->execute([$householdId]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'type' => 'chore',
                'id' => (int) $r['id'],
                'kid' => ['id' => (int) $r['kid_id'], 'nickname' => $r['nickname'], 'avatar' => $r['avatar']],
                'title' => $r['title'],
                'slot_label' => (int) $r['slot_count'] > 1 ? $r['label'] : null,
                'value' => $r['value'] !== null ? (int) $r['value'] : null,
                'currency' => $r['currency'],
                'reported_at' => $r['reported_at'],
                'reported_local' => $time->utcToLocal($r['reported_at']),
                'is_late' => (bool) $r['is_late'],
            ];
        }
        return ['count' => count($items), 'items' => $items];
    }

    public function approvalCount(int $householdId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM chore_instances WHERE household_id = ? AND status = 'reported_done'");
        $stmt->execute([$householdId]);
        return (int) $stmt->fetchColumn();
    }

    // ---- the kid's actions ------------------------------------------------------------------------

    public function report(int $householdId, int $kidId, int $instanceId): array
    {
        return $this->transition($instanceId, function (array $i, HouseholdTime $time, string $now) use ($kidId) {
            if ((int) $i['kid_id'] !== $kidId) {
                throw new ApiException(404, 'Chore not found.');
            }
            if ($i['status'] === 'reported_done') {
                throw new ApiException(409, 'You already marked this done. A parent will check it.');
            }
            if ($i['status'] === 'approved') {
                throw new ApiException(409, 'This one is already approved.');
            }
            if (!in_array($i['status'], ['assigned', 'sent_back'], true) || (int) $i['chore_active'] !== 1) {
                throw new ApiException(409, "This chore isn't available anymore.");
            }
            $today = $time->today();
            if ($i['schedule_type'] === 'daily' && $i['local_date'] !== $today) {
                throw new ApiException(409, 'That chore is from an earlier day.');
            }
            $secondsNow = $time->secondsIntoDay($this->clock->now());
            if ($i['available_from'] !== null && $secondsNow < $time->clockSecondsIntoDay($i['available_from'])) {
                throw new ApiException(422, 'This one opens at ' . self::clock12($i['available_from']) . '.');
            }
            $late = $i['due_by'] !== null && $secondsNow > $time->clockSecondsIntoDay($i['due_by']);

            if ((int) $i['auto_approve'] === 1) {
                $this->pdo->prepare(
                    "UPDATE chore_instances SET status = 'approved', reported_at = ?, is_late = ?, approved_by_parent_id = NULL,
                       approved_at = ?, send_back_note = NULL, updated_at = ? WHERE id = ?"
                )->execute([$now, $late ? 1 : 0, $now, $now, $i['id']]);
                $this->earn($i, $time, $now, null);
            } else {
                $this->pdo->prepare(
                    "UPDATE chore_instances SET status = 'reported_done', reported_at = ?, is_late = ?, send_back_note = NULL, updated_at = ? WHERE id = ?"
                )->execute([$now, $late ? 1 : 0, $now, $i['id']]);
            }
        }, $householdId);
    }

    /** The kid takes back "Done" until a parent approves it. */
    public function undo(int $householdId, int $kidId, int $instanceId): array
    {
        return $this->transition($instanceId, function (array $i, HouseholdTime $time, string $now) use ($kidId) {
            if ((int) $i['kid_id'] !== $kidId) {
                throw new ApiException(404, 'Chore not found.');
            }
            if ($i['status'] === 'approved') {
                throw new ApiException(409, 'A parent already approved this one, so it can\'t be undone.');
            }
            if ($i['status'] !== 'reported_done') {
                throw new ApiException(409, "This one isn't marked done.");
            }
            $this->pdo->prepare(
                "UPDATE chore_instances SET status = 'assigned', reported_at = NULL, is_late = 0, updated_at = ? WHERE id = ?"
            )->execute([$now, $i['id']]);
        }, $householdId);
    }

    // ---- the parent's actions ---------------------------------------------------------------------

    public function approve(int $householdId, int $parentId, int $instanceId): array
    {
        return $this->transition($instanceId, function (array $i, HouseholdTime $time, string $now) use ($parentId) {
            if ($i['status'] === 'approved') {
                return; // already done; repeating the request changes nothing (and writes no second earning)
            }
            if ($i['status'] !== 'reported_done') {
                throw new ApiException(409, "That chore hasn't been marked done yet. Use \"Mark done\" instead.");
            }
            $this->approveRow($i, $time, $now, $parentId);
        }, $householdId);
    }

    public function sendBack(int $householdId, int $instanceId, ?string $note): array
    {
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 200) {
            throw new ApiException(422, 'Keep the note under 200 characters.');
        }
        return $this->transition($instanceId, function (array $i, HouseholdTime $time, string $now) use ($note) {
            if ($i['status'] !== 'reported_done') {
                throw new ApiException(409, $i['status'] === 'approved' ? 'That one is already approved.' : "That chore isn't waiting for approval.");
            }
            $this->pdo->prepare(
                "UPDATE chore_instances SET status = 'sent_back', sent_back_at = ?, send_back_note = ?, updated_at = ? WHERE id = ?"
            )->execute([$now, $note !== '' ? $note : null, $now, $i['id']]);
        }, $householdId);
    }

    /** The parent marks a chore done directly: approved in one step (not flagged late). */
    public function markDone(int $householdId, int $parentId, int $instanceId): array
    {
        return $this->transition($instanceId, function (array $i, HouseholdTime $time, string $now) use ($parentId) {
            if ($i['status'] === 'approved') {
                return;
            }
            if (!in_array($i['status'], ['assigned', 'sent_back', 'reported_done'], true) || (int) $i['chore_active'] !== 1 && $i['status'] !== 'reported_done') {
                throw new ApiException(409, "This chore can't be marked done.");
            }
            if ($i['status'] !== 'reported_done') {
                $this->pdo->prepare('UPDATE chore_instances SET reported_at = ?, is_late = 0, send_back_note = NULL WHERE id = ?')->execute([$now, $i['id']]);
                $i['reported_at'] = $now;
            }
            $this->approveRow($i, $time, $now, $parentId);
        }, $householdId);
    }

    // ---- internals -----------------------------------------------------------------------------------

    /**
     * Runs $change on a locked instance inside a transaction, then returns the instance's fresh JSON.
     * The row lock makes repeated or simultaneous requests safe (e.g. a double-tapped Approve).
     */
    private function transition(int $instanceId, callable $change, int $householdId): array
    {
        $time = $this->chores->householdTime($householdId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT i.*, c.schedule_type, c.auto_approve, c.active AS chore_active, s.available_from, s.due_by
                 FROM chore_instances i JOIN chores c ON c.id = i.chore_id JOIN chore_slots s ON s.id = i.slot_id
                 WHERE i.id = ? FOR UPDATE'
            );
            $stmt->execute([$instanceId]);
            $i = $stmt->fetch();
            if (!$i || (int) $i['household_id'] !== $householdId) {
                throw new ApiException(404, 'Chore not found.');
            }
            $change($i, $time, $this->clock->nowSql());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->single($instanceId, $time);
    }

    private function approveRow(array $i, HouseholdTime $time, string $now, int $parentId): void
    {
        $this->pdo->prepare(
            "UPDATE chore_instances SET status = 'approved', approved_by_parent_id = ?, approved_at = ?, updated_at = ? WHERE id = ?"
        )->execute([$parentId, $now, $now, $i['id']]);
        $this->earn($i, $time, $now, $parentId);
    }

    /**
     * Record the earning in the PENDING bucket — exactly once per instance, and only if the chore has a value.
     * The earning belongs to the chore's day; for one-time chores, the day it was reported done.
     */
    private function earn(array $i, HouseholdTime $time, string $now, ?int $parentId): void
    {
        $value = $i['value'] !== null ? (int) $i['value'] : 0;
        if ($value <= 0 || $i['currency'] === null) {
            return;
        }
        $exists = $this->pdo->prepare("SELECT 1 FROM ledger_entries WHERE chore_instance_id = ? AND entry_type = 'earning' LIMIT 1");
        $exists->execute([$i['id']]);
        if ($exists->fetchColumn()) {
            return;
        }
        $reported = $this->pdo->prepare('SELECT reported_at FROM chore_instances WHERE id = ?');
        $reported->execute([$i['id']]);
        $reportedAt = $reported->fetchColumn() ?: $now;
        $day = $i['schedule_type'] === 'one_off'
            ? $time->dayFor(new DateTimeImmutable($reportedAt, new DateTimeZone('UTC')))
            : $i['local_date'];
        $this->ledger->append((int) $i['household_id'], (int) $i['kid_id'], $i['currency'], 'pending', 'earning', $value, [
            'chore_instance_id' => (int) $i['id'],
            'local_date' => $day,
            'created_by_type' => $parentId !== null ? 'parent' : 'system',
            'created_by_id' => $parentId,
        ]);
    }

    private function single(int $instanceId, HouseholdTime $time): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.*, c.title, c.schedule_type, c.due_date, c.auto_approve, c.active AS chore_active,
                    s.label, s.available_from, s.due_by, s.sort_order,
                    (SELECT COUNT(*) FROM chore_slots s2 WHERE s2.chore_id = c.id AND s2.active = 1) AS slot_count
             FROM chore_instances i JOIN chores c ON c.id = i.chore_id JOIN chore_slots s ON s.id = i.slot_id
             WHERE i.id = ?"
        );
        $stmt->execute([$instanceId]);
        return $this->rowJson($stmt->fetch(), $time, $time->today());
    }

    /** What a screen needs to draw one row. */
    private function rowJson(array $r, HouseholdTime $time, string $today): array
    {
        $secondsNow = $time->secondsIntoDay($this->clock->now());
        $openToAct = in_array($r['status'], ['assigned', 'sent_back'], true) && (int) $r['chore_active'] === 1;
        $available = $r['available_from'] === null || $secondsNow >= $time->clockSecondsIntoDay($r['available_from']);
        $pastDueBy = $r['due_by'] !== null && $secondsNow > $time->clockSecondsIntoDay($r['due_by']);
        $oneOff = $r['schedule_type'] === 'one_off';

        return [
            'id' => (int) $r['id'],
            'kid_id' => (int) $r['kid_id'],
            'title' => $r['title'],
            'slot_label' => (int) $r['slot_count'] > 1 ? $r['label'] : null,
            'value' => $r['value'] !== null ? (int) $r['value'] : null,
            'currency' => $r['currency'],
            'status' => $r['status'],
            'is_late' => (bool) $r['is_late'],
            'past_due_by' => $openToAct && $pastDueBy,
            'is_one_off' => $oneOff,
            'due_date' => $oneOff ? $r['due_date'] : null,
            'past_due_date' => $oneOff && $r['due_date'] !== null && $r['due_date'] < $today && $r['status'] !== 'approved',
            'due_by' => $r['due_by'] !== null ? substr($r['due_by'], 0, 5) : null,
            'opens_at' => $openToAct && !$available ? substr($r['available_from'], 0, 5) : null,
            'can_report' => $openToAct && $available,
            'reported_at' => $r['reported_at'],
            'send_back_note' => $r['status'] === 'sent_back' ? $r['send_back_note'] : null,
            'auto_approve' => (bool) $r['auto_approve'],
        ];
    }

    /** '18:00:00' -> '6:00 PM' (pure formatting; no clock involved). */
    private static function clock12(string $time): string
    {
        return DateTimeImmutable::createFromFormat('H:i:s', strlen($time) === 5 ? $time . ':00' : $time)->format('g:i A');
    }
}
