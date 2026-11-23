<?php
declare(strict_types=1);

namespace App\Chores;

use App\Clock;
use App\Http\ApiException;
use App\HouseholdTime;
use PDO;

/** Creating, listing, editing, and removing chores (the parent's side). */
final class ChoreService
{
    public function __construct(private PDO $pdo, private Clock $clock) {}

    public function householdTime(int $householdId): HouseholdTime
    {
        $stmt = $this->pdo->prepare('SELECT timezone, day_end_time FROM households WHERE id = ?');
        $stmt->execute([$householdId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException(404, 'Household not found.');
        }
        return HouseholdTime::forHousehold($row, $this->clock);
    }

    /**
     * Create one copy of the chore per kid; the copies share a group_id.
     * @param list<mixed> $kidIds
     * @return list<int> the new chore ids (one per kid)
     */
    public function create(int $householdId, array $body): array
    {
        $data = ChoreInput::clean($body, $this->householdTime($householdId));
        $kidIds = $this->validKids($householdId, $body['kid_ids'] ?? null);
        $groupId = bin2hex(random_bytes(16));
        $now = $this->clock->nowSql();

        $this->pdo->beginTransaction();
        try {
            $ids = [];
            foreach ($kidIds as $kidId) {
                $this->pdo->prepare(
                    'INSERT INTO chores (household_id, kid_id, group_id, title, value, currency, is_required, schedule_type,
                       days_of_week, due_date, overdue_behavior, auto_approve, active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 1, ?, ?)'
                )->execute([$householdId, $kidId, $groupId, $data['title'], $data['value'], $data['currency'],
                    $data['schedule_type'], $data['days_of_week'], $data['due_date'], $data['overdue_behavior'],
                    $data['auto_approve'], $now, $now]);
                $choreId = (int) $this->pdo->lastInsertId();
                foreach ($data['slots'] as $i => $slot) {
                    $this->insertSlot($choreId, $slot, $i);
                }
                $ids[] = $choreId;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $ids;
    }

    /**
     * Edit a chore. $applyTo 'all' edits every copy in its group (all the kids it was assigned to);
     * 'one' edits only the copy $choreId. Takes effect from the household's current day forward:
     * anything already reported or approved keeps its original value and details.
     */
    public function update(int $householdId, int $choreId, array $body, string $applyTo): void
    {
        $chore = $this->find($householdId, $choreId);
        $time = $this->householdTime($householdId);
        $data = ChoreInput::clean($body, $time);
        if ($data['schedule_type'] !== $chore['schedule_type']) {
            throw new ApiException(422, "You can't switch a chore between repeating and one time. Remove it and add a new one.");
        }
        $targets = $applyTo === 'one' ? [$chore] : $this->copies($householdId, $chore['group_id']);

        // Slots arrive with the ids of the copy being edited; other copies are matched by position.
        $positionById = [];
        foreach ($this->activeSlots((int) $chore['id']) as $i => $slot) {
            $positionById[(int) $slot['id']] = $i;
        }
        foreach ($data['slots'] as $slot) {
            if ($slot['id'] !== null && !isset($positionById[$slot['id']])) {
                throw new ApiException(422, 'One of those times of day no longer exists. Reload and try again.');
            }
        }

        $today = $time->today();
        $this->pdo->beginTransaction();
        try {
            foreach ($targets as $target) {
                $this->updateCopy($target, $data, $positionById, $today);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** "Remove" deactivates; history stays. Today's untouched to-dos disappear; reported/approved ones remain. */
    public function remove(int $householdId, int $choreId, string $applyTo): void
    {
        $chore = $this->find($householdId, $choreId);
        $today = $this->householdTime($householdId)->today();
        $targets = $applyTo === 'one' ? [$chore] : $this->copies($householdId, $chore['group_id']);
        foreach ($targets as $t) {
            $this->pdo->prepare('UPDATE chores SET active = 0, updated_at = ? WHERE id = ?')->execute([$this->clock->nowSql(), $t['id']]);
            $this->deleteUntouched((int) $t['id'], null, $t['schedule_type'] === 'one_off', $today);
        }
    }

    /** All active chores, grouped (one entry per group, listing each kid it is assigned to). */
    public function listGroups(int $householdId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, k.nickname, k.avatar, k.active AS kid_active FROM chores c JOIN kids k ON k.id = c.kid_id
             WHERE c.household_id = ? AND c.active = 1 ORDER BY c.title, c.id'
        );
        $stmt->execute([$householdId]);
        $groups = [];
        foreach ($stmt->fetchAll() as $c) {
            $key = $c['group_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'group_id' => $key,
                    'id' => (int) $c['id'], // the copy the editor works from
                    'title' => $c['title'],
                    'value' => $c['value'] !== null ? (int) $c['value'] : null,
                    'currency' => $c['currency'],
                    'schedule_type' => $c['schedule_type'],
                    'days' => self::maskToDays((int) $c['days_of_week']),
                    'due_date' => $c['due_date'],
                    'auto_approve' => (bool) $c['auto_approve'],
                    'overdue_behavior' => $c['overdue_behavior'],
                    'slots' => array_map(fn ($s) => [
                        'id' => (int) $s['id'], 'label' => $s['label'],
                        'available_from' => $s['available_from'] !== null ? substr($s['available_from'], 0, 5) : null,
                        'due_by' => $s['due_by'] !== null ? substr($s['due_by'], 0, 5) : null,
                    ], $this->activeSlots((int) $c['id'])),
                    'kids' => [],
                ];
            }
            $groups[$key]['kids'][] = [
                'chore_id' => (int) $c['id'], 'kid_id' => (int) $c['kid_id'],
                'nickname' => $c['nickname'], 'avatar' => $c['avatar'], 'active' => (bool) $c['kid_active'],
                // This copy's own slot ids, in display order (the editor needs them to edit just one kid's copy).
                'slot_ids' => array_map(fn ($s) => (int) $s['id'], $this->activeSlots((int) $c['id'])),
            ];
        }
        return array_values($groups);
    }

    public static function maskToDays(int $mask): array
    {
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            if ($mask & (1 << ($d - 1))) {
                $days[] = $d;
            }
        }
        return $days;
    }

    // ---------------------------------------------------------------------------------------------

    private function find(int $householdId, int $choreId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chores WHERE id = ? AND household_id = ? AND active = 1');
        $stmt->execute([$choreId, $householdId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException(404, 'Chore not found.');
        }
        return $row;
    }

    private function copies(int $householdId, string $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chores WHERE household_id = ? AND group_id = ? AND active = 1 ORDER BY id');
        $stmt->execute([$householdId, $groupId]);
        return $stmt->fetchAll();
    }

    /** @return list<array> active slots in display order */
    private function activeSlots(int $choreId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chore_slots WHERE chore_id = ? AND active = 1 ORDER BY sort_order, id');
        $stmt->execute([$choreId]);
        return $stmt->fetchAll();
    }

    /** @return list<int> */
    private function validKids(int $householdId, mixed $kidIds): array
    {
        if (!is_array($kidIds) || !$kidIds) {
            throw new ApiException(422, 'Choose at least one kid.');
        }
        $ids = [];
        foreach ($kidIds as $id) {
            if (!is_int($id)) {
                throw new ApiException(422, 'Choose kids from the list.');
            }
            $ids[$id] = $id;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM kids WHERE household_id = ? AND active = 1 AND id = ?');
        foreach ($ids as $id) {
            $stmt->execute([$householdId, $id]);
            if (!$stmt->fetchColumn()) {
                throw new ApiException(422, 'Choose kids from the list.');
            }
        }
        return array_values($ids);
    }

    private function insertSlot(int $choreId, array $slot, int $order): int
    {
        $this->pdo->prepare('INSERT INTO chore_slots (chore_id, label, sort_order, available_from, due_by, active) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$choreId, $slot['label'], $order, $slot['available_from'], $slot['due_by']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function updateCopy(array $chore, array $data, array $positionById, string $today): void
    {
        $choreId = (int) $chore['id'];
        $now = $this->clock->nowSql();
        $this->pdo->prepare(
            'UPDATE chores SET title = ?, value = ?, currency = ?, days_of_week = ?, due_date = ?, overdue_behavior = ?,
               auto_approve = ?, updated_at = ? WHERE id = ?'
        )->execute([$data['title'], $data['value'], $data['currency'], $data['days_of_week'], $data['due_date'],
            $data['overdue_behavior'], $data['auto_approve'], $now, $choreId]);

        // Slots: update the ones that were kept, add new ones, deactivate the ones that were dropped.
        $existing = $this->activeSlots($choreId);
        $kept = [];
        foreach ($data['slots'] as $order => $slot) {
            $slotId = null;
            if ($slot['id'] !== null) {
                $slotId = isset($existing[$positionById[$slot['id']]]) ? (int) $existing[$positionById[$slot['id']]]['id'] : null;
            }
            if ($slotId !== null) {
                $this->pdo->prepare('UPDATE chore_slots SET label = ?, sort_order = ?, available_from = ?, due_by = ? WHERE id = ?')
                    ->execute([$slot['label'], $order, $slot['available_from'], $slot['due_by'], $slotId]);
            } else {
                $slotId = $this->insertSlot($choreId, $slot, $order);
            }
            $kept[$slotId] = true;
        }
        $oneOff = $chore['schedule_type'] === 'one_off';
        foreach ($existing as $slot) {
            if (!isset($kept[(int) $slot['id']])) {
                $this->pdo->prepare('UPDATE chore_slots SET active = 0 WHERE id = ?')->execute([$slot['id']]);
                $this->deleteUntouched($choreId, (int) $slot['id'], $oneOff, $today);
            }
        }

        // Today's list: drop to-dos for a day that is no longer scheduled; refresh the value on untouched ones.
        if (!$oneOff) {
            $scheduled = ($data['days_of_week'] & (1 << (HouseholdTime::isoWeekday($today) - 1))) !== 0;
            if (!$scheduled) {
                $this->deleteUntouched($choreId, null, false, $today);
            }
        }
        $this->pdo->prepare(
            "UPDATE chore_instances SET value = ?, currency = ?, updated_at = ?
             WHERE chore_id = ? AND status IN ('assigned', 'sent_back') AND (local_date = ? OR ? = 1)"
        )->execute([$data['value'], $data['currency'], $now, $choreId, $today, $oneOff ? 1 : 0]);
    }

    /** Delete instances nobody has touched yet (status 'assigned') from today on — one-time chores: all of them. */
    private function deleteUntouched(int $choreId, ?int $slotId, bool $oneOff, string $today): void
    {
        $sql = "DELETE FROM chore_instances WHERE chore_id = ? AND status = 'assigned' AND (local_date >= ? OR ? = 1)";
        $params = [$choreId, $today, $oneOff ? 1 : 0];
        if ($slotId !== null) {
            $sql .= ' AND slot_id = ?';
            $params[] = $slotId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }
}
