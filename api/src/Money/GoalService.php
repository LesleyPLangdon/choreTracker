<?php
declare(strict_types=1);

namespace App\Money;

use App\Clock;
use App\Http\ApiException;
use App\Ledger;
use App\Support\Links;
use PDO;

/**
 * Goals: saving for something specific. A goal's money sits in the `goal` bucket (linked by goal_id). The kid moves it in
 * and out of Spending (and between goals); a parent marks it Used. Goal balances are computed from the ledger.
 */
final class GoalService
{
    private const LIVE = ['requested', 'active', 'reached'];
    private const MAX_TARGET = 1000000; // whole points, or cents

    public function __construct(private PDO $pdo, private Clock $clock, private Ledger $ledger) {}

    // ---- reading ---------------------------------------------------------------------------------

    public function json(array $g): array
    {
        $pending = null;
        if ($g['change_requested_at'] !== null) {
            $pending = [
                'link_changed' => (bool) $g['pending_link_set'],
                'link' => $g['pending_link'],
                'link_host' => Links::host($g['pending_link']),
                'target' => $g['pending_target'] !== null ? (int) $g['pending_target'] : null,
            ];
        }
        $balance = $this->ledger->goalBalance((int) $g['id']);
        return [
            'id' => (int) $g['id'],
            'kid_id' => (int) $g['kid_id'],
            'name' => $g['name'],
            'currency' => $g['currency'],
            'target' => (int) $g['target'],
            'balance' => $balance,
            'status' => $g['status'],
            'link' => $g['link'],
            'link_host' => Links::host($g['link']),
            'pending_change' => $pending,
            'reward_id' => $g['reward_id'] !== null ? (int) $g['reward_id'] : null,
            'decision_note' => $g['decision_note'],
        ];
    }

    /** A kid's goals: the live ones, plus recently used ones. */
    public function forKid(int $kidId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM goals WHERE kid_id = ? AND status IN ('requested', 'active', 'reached', 'used', 'denied')
             ORDER BY FIELD(status, 'reached', 'active', 'requested', 'used', 'denied'), id DESC LIMIT 30"
        );
        $stmt->execute([$kidId]);
        return array_map(fn ($g) => $this->json($g), $stmt->fetchAll());
    }

    public function find(int $householdId, int $goalId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM goals WHERE id = ? AND household_id = ?');
        $stmt->execute([$goalId, $householdId]);
        $g = $stmt->fetch();
        if (!$g) {
            throw new ApiException(404, 'Goal not found.');
        }
        return $g;
    }

    private function findForKid(int $householdId, int $kidId, int $goalId): array
    {
        $g = $this->find($householdId, $goalId);
        if ((int) $g['kid_id'] !== $kidId) {
            throw new ApiException(404, 'Goal not found.');
        }
        return $g;
    }

    /** Can this kid start another goal? (The free plan allows one at a time.) */
    public function hasRoom(int $householdId, int $kidId): bool
    {
        $plan = $this->pdo->prepare('SELECT plan FROM households WHERE id = ?');
        $plan->execute([$householdId]);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM goals WHERE kid_id = ? AND status IN ('requested', 'active', 'reached')");
        $count->execute([$kidId]);
        return (int) $count->fetchColumn() < Plans::maxActiveGoals((string) $plan->fetchColumn());
    }

    // ---- creating and editing ----------------------------------------------------------------------

    /**
     * A kid asks for a goal (status 'requested', goes to the parent's approvals) or a parent creates one directly
     * (already approved). The free plan allows one at a time.
     */
    public function create(int $householdId, int $kidId, array $in, ?int $parentId, ?int $rewardId = null): array
    {
        $name = $this->name($in['name'] ?? '');
        $currency = $this->currency($in['currency'] ?? '');
        $target = $this->target($in['target'] ?? null);
        $link = Links::clean($in['link'] ?? null);
        return Locks::kid($this->pdo, $kidId, function () use ($householdId, $kidId, $name, $currency, $target, $link, $parentId, $rewardId) {
            $this->requireRoom($householdId, $kidId, $parentId !== null);
            $now = $this->clock->nowSql();
            $approved = $parentId !== null || $rewardId !== null; // rewards are the parent's own, so their goals start approved
            $this->pdo->prepare(
                'INSERT INTO goals (household_id, kid_id, name, currency, target, link, status, reward_id, approved_by_parent_id, approved_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$householdId, $kidId, $name, $currency, $target, $link, $approved ? 'active' : 'requested', $rewardId,
                $parentId, $approved ? $now : null, $now, $now]);
            return $this->json($this->find($householdId, (int) $this->pdo->lastInsertId()));
        });
    }

    /** The kid edits their own goal: the name any time; link/price directly while it's still a request, otherwise via the parent. */
    public function kidEdit(int $householdId, int $kidId, int $goalId, array $in): array
    {
        return Locks::kid($this->pdo, $kidId, function () use ($householdId, $kidId, $goalId, $in) {
            $g = $this->findForKid($householdId, $kidId, $goalId);
            if (!in_array($g['status'], self::LIVE, true)) {
                throw new ApiException(409, "That goal can't be changed anymore.");
            }
            $now = $this->clock->nowSql();
            $name = array_key_exists('name', $in) ? $this->name($in['name']) : $g['name'];
            $newLink = array_key_exists('link', $in) ? Links::clean($in['link']) : $g['link'];
            $newTarget = array_key_exists('target', $in) ? $this->target($in['target']) : (int) $g['target'];

            if ($g['status'] === 'requested') {
                $this->pdo->prepare('UPDATE goals SET name = ?, link = ?, target = ?, updated_at = ? WHERE id = ?')->execute([$name, $newLink, $newTarget, $now, $goalId]);
            } else {
                // Approved: a different link or price waits for a parent. Changing it back to what it was cancels the request.
                $linkChanged = $newLink !== $g['link'];
                $targetChanged = $newTarget !== (int) $g['target'];
                $this->pdo->prepare(
                    'UPDATE goals SET name = ?, pending_link = ?, pending_link_set = ?, pending_target = ?, change_requested_at = ?, updated_at = ? WHERE id = ?'
                )->execute([
                    $name, $linkChanged ? $newLink : null, $linkChanged ? 1 : 0, $targetChanged ? $newTarget : null,
                    ($linkChanged || $targetChanged) ? ($g['change_requested_at'] ?? $now) : null, $now, $goalId,
                ]);
            }
            return $this->json($this->find($householdId, $goalId));
        });
    }

    /** A parent can edit anything on a goal, any time (a pending kid change is replaced by what the parent sets). */
    public function parentEdit(int $householdId, int $goalId, array $in): array
    {
        $g = $this->find($householdId, $goalId);
        return Locks::kid($this->pdo, (int) $g['kid_id'], function () use ($householdId, $goalId, $in) {
            $g = $this->find($householdId, $goalId);
            if (!in_array($g['status'], self::LIVE, true)) {
                throw new ApiException(409, "That goal can't be changed anymore.");
            }
            if (array_key_exists('currency', $in) && $in['currency'] !== $g['currency']) {
                throw new ApiException(422, "A goal's currency can't be changed. Cancel it and make a new one.");
            }
            $this->applyEdits($g, $in);
            $this->clearPending($goalId);
            $this->syncReached($householdId, $goalId);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    /** Approve a kid's goal request (the parent may fix the name, price, or link first). */
    public function approve(int $householdId, int $parentId, int $goalId, array $edits, ?string $note): array
    {
        $g = $this->find($householdId, $goalId);
        return Locks::kid($this->pdo, (int) $g['kid_id'], function () use ($householdId, $parentId, $goalId, $edits, $note) {
            $g = $this->find($householdId, $goalId);
            if ($g['status'] !== 'requested') {
                throw new ApiException(409, "That goal isn't waiting for approval.");
            }
            $this->applyEdits($g, $edits);
            $now = $this->clock->nowSql();
            $this->pdo->prepare("UPDATE goals SET status = 'active', approved_by_parent_id = ?, approved_at = ?, decision_note = ?, updated_at = ? WHERE id = ?")
                ->execute([$parentId, $now, $this->note($note), $now, $goalId]);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    public function deny(int $householdId, int $goalId, ?string $note): array
    {
        $g = $this->find($householdId, $goalId);
        if ($g['status'] !== 'requested') {
            throw new ApiException(409, "That goal isn't waiting for approval.");
        }
        $this->pdo->prepare("UPDATE goals SET status = 'denied', decision_note = ?, updated_at = ? WHERE id = ?")->execute([$this->note($note), $this->clock->nowSql(), $goalId]);
        return $this->json($this->find($householdId, $goalId));
    }

    /** Approve a kid's change to an approved goal's link or price (the parent may adjust it first). */
    public function approveChange(int $householdId, int $goalId, array $edits): array
    {
        $g = $this->find($householdId, $goalId);
        return Locks::kid($this->pdo, (int) $g['kid_id'], function () use ($householdId, $goalId, $edits) {
            $g = $this->find($householdId, $goalId);
            if ($g['change_requested_at'] === null) {
                throw new ApiException(409, 'There is no change waiting.');
            }
            $proposed = [];
            if ((int) $g['pending_link_set'] === 1) {
                $proposed['link'] = $g['pending_link'];
            }
            if ($g['pending_target'] !== null) {
                $proposed['target'] = (int) $g['pending_target'];
            }
            $this->applyEdits($g, $edits + $proposed);
            $this->clearPending($goalId);
            $this->syncReached($householdId, $goalId);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    public function denyChange(int $householdId, int $goalId): array
    {
        $g = $this->find($householdId, $goalId);
        if ($g['change_requested_at'] === null) {
            throw new ApiException(409, 'There is no change waiting.');
        }
        $this->clearPending($goalId);
        return $this->json($this->find($householdId, $goalId));
    }

    // ---- moving money -------------------------------------------------------------------------------

    /**
     * The kid's own moves: 'in' (Spending → goal), 'out' (goal → Spending), 'transfer' (goal → another goal, same
     * currency). Never more than what's there. Every move is a ledger entry the parent can see in the activity list.
     */
    public function move(int $householdId, int $kidId, int $goalId, string $action, int $amount, ?int $toGoalId = null): array
    {
        if ($amount < 1 || $amount > self::MAX_TARGET * 10) {
            throw new ApiException(422, 'Enter an amount greater than zero.');
        }
        if (!in_array($action, ['in', 'out', 'transfer'], true)) {
            throw new ApiException(422, 'Choose what to do with the money.');
        }
        return Locks::kid($this->pdo, $kidId, function () use ($householdId, $kidId, $goalId, $action, $amount, $toGoalId) {
            $g = $this->findForKid($householdId, $kidId, $goalId);
            $this->requireFunded($g);
            $batch = $this->ledger->newBatch();
            $by = ['created_by_type' => 'kid', 'created_by_id' => $kidId, 'batch' => $batch];

            if ($action === 'in') {
                if ($this->ledger->balance($kidId, $g['currency'], 'spending') < $amount) {
                    throw new ApiException(422, "That's more than you have in Spending.");
                }
                $this->ledger->append($householdId, $kidId, $g['currency'], 'spending', 'goal_transfer', -$amount, $by + ['goal_id' => $goalId, 'action' => 'goal_in']);
                $this->ledger->append($householdId, $kidId, $g['currency'], 'goal', 'goal_transfer', $amount, $by + ['goal_id' => $goalId, 'action' => 'goal_in']);
            } elseif ($action === 'out') {
                if ($this->ledger->goalBalance($goalId) < $amount) {
                    throw new ApiException(422, "That's more than is in this goal.");
                }
                $this->ledger->append($householdId, $kidId, $g['currency'], 'goal', 'goal_transfer', -$amount, $by + ['goal_id' => $goalId, 'action' => 'goal_out']);
                $this->ledger->append($householdId, $kidId, $g['currency'], 'spending', 'goal_transfer', $amount, $by + ['goal_id' => $goalId, 'action' => 'goal_out']);
            } else {
                if ($toGoalId === null || $toGoalId === $goalId) {
                    throw new ApiException(422, 'Choose another goal to move the money to.');
                }
                $to = $this->findForKid($householdId, $kidId, $toGoalId);
                $this->requireFunded($to);
                if ($to['currency'] !== $g['currency']) {
                    throw new ApiException(422, 'Money can only move between goals in the same currency.');
                }
                if ($this->ledger->goalBalance($goalId) < $amount) {
                    throw new ApiException(422, "That's more than is in this goal.");
                }
                $this->ledger->append($householdId, $kidId, $g['currency'], 'goal', 'goal_transfer', -$amount, $by + ['goal_id' => $goalId, 'action' => 'goal_move']);
                $this->ledger->append($householdId, $kidId, $g['currency'], 'goal', 'goal_transfer', $amount, $by + ['goal_id' => $toGoalId, 'action' => 'goal_move']);
                $this->syncReached($householdId, $toGoalId);
            }
            $this->syncReached($householdId, $goalId);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    /**
     * The parent marks a goal Used (bought / given). The goal's money leaves (for a money goal, this is the payout for
     * the item). Anything beyond the price goes back to Spending. Allowed before it's fully funded: the kid's balance is
     * used and the parent covers the rest in real life.
     */
    public function markUsed(int $householdId, int $parentId, int $goalId): array
    {
        $g = $this->find($householdId, $goalId);
        return Locks::kid($this->pdo, (int) $g['kid_id'], function () use ($householdId, $parentId, $goalId) {
            $g = $this->find($householdId, $goalId);
            if (!in_array($g['status'], ['active', 'reached'], true)) {
                throw new ApiException(409, "That goal can't be marked used right now.");
            }
            $kidId = (int) $g['kid_id'];
            $this->consume($householdId, $kidId, $g, (int) $g['target'], 'goal_used', ['created_by_type' => 'parent', 'created_by_id' => $parentId]);
            $now = $this->clock->nowSql();
            $this->pdo->prepare("UPDATE goals SET status = 'used', used_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $goalId]);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    /** Cancel a goal (the kid or a parent): whatever it holds goes back to Spending. */
    public function cancel(int $householdId, int $goalId, ?int $kidId, ?int $parentId): array
    {
        $g = $kidId !== null ? $this->findForKid($householdId, $kidId, $goalId) : $this->find($householdId, $goalId);
        return Locks::kid($this->pdo, (int) $g['kid_id'], function () use ($householdId, $goalId, $kidId, $parentId) {
            $g = $this->find($householdId, $goalId);
            if (!in_array($g['status'], self::LIVE, true)) {
                throw new ApiException(409, "That goal can't be cancelled.");
            }
            $balance = $this->ledger->goalBalance($goalId);
            if ($balance > 0) {
                $by = $kidId !== null ? ['created_by_type' => 'kid', 'created_by_id' => $kidId] : ['created_by_type' => 'parent', 'created_by_id' => $parentId];
                $ref = $by + ['goal_id' => $goalId, 'action' => 'goal_cancel', 'batch' => $this->ledger->newBatch()];
                $this->ledger->append($householdId, (int) $g['kid_id'], $g['currency'], 'goal', 'goal_transfer', -$balance, $ref);
                $this->ledger->append($householdId, (int) $g['kid_id'], $g['currency'], 'spending', 'goal_transfer', $balance, $ref);
            }
            $this->pdo->prepare("UPDATE goals SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([$this->clock->nowSql(), $goalId]);
            return $this->json($this->find($householdId, $goalId));
        });
    }

    /**
     * Take up to $price out of a goal (the item is bought) and return the rest to Spending. Used by "mark used" and by
     * rewards that are paid from their goal. Returns how much the goal paid. Caller holds the kid lock.
     * @param array{created_by_type:string, created_by_id:int} $by
     */
    public function consume(int $householdId, int $kidId, array $goal, int $price, string $action, array $by, ?int $rewardRequestId = null, string $entryType = 'goal_transfer'): int
    {
        $goalId = (int) $goal['id'];
        $balance = $this->ledger->goalBalance($goalId);
        $paid = min($balance, $price);
        $ref = $by + ['goal_id' => $goalId, 'batch' => $this->ledger->newBatch()];
        if ($rewardRequestId !== null) {
            $ref['reward_request_id'] = $rewardRequestId;
        }
        if ($paid > 0) {
            $this->ledger->append($householdId, $kidId, $goal['currency'], 'goal', $entryType, -$paid, $ref + ['action' => $action]);
        }
        $left = $balance - $paid;
        if ($left > 0) {
            $this->ledger->append($householdId, $kidId, $goal['currency'], 'goal', 'goal_transfer', -$left, $ref + ['action' => 'goal_leftover']);
            $this->ledger->append($householdId, $kidId, $goal['currency'], 'spending', 'goal_transfer', $left, $ref + ['action' => 'goal_leftover']);
        }
        return $paid;
    }

    // ---- approvals list ----------------------------------------------------------------------------

    /** Goal requests, pending changes, and reached goals, for the parent's approvals list. */
    public function approvalItems(int $householdId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT g.*, k.nickname, k.avatar FROM goals g JOIN kids k ON k.id = g.kid_id
             WHERE g.household_id = ? AND (g.status IN ('requested', 'reached') OR (g.status = 'active' AND g.change_requested_at IS NOT NULL))
             ORDER BY g.updated_at DESC, g.id DESC"
        );
        $stmt->execute([$householdId]);
        $items = [];
        foreach ($stmt->fetchAll() as $g) {
            $type = $g['status'] === 'requested' ? 'goal_request' : ($g['status'] === 'reached' ? 'goal_reached' : 'goal_change');
            if ($g['status'] === 'reached' && $g['change_requested_at'] !== null) {
                // A reached goal with a pending change shows both; the change is handled first.
                $items[] = $this->item('goal_change', $g);
            }
            $items[] = $this->item($type, $g);
        }
        return $items;
    }

    public function approvalCount(int $householdId): int
    {
        return count($this->approvalItems($householdId));
    }

    private function item(string $type, array $g): array
    {
        return [
            'type' => $type,
            'id' => (int) $g['id'],
            'kid' => ['id' => (int) $g['kid_id'], 'nickname' => $g['nickname'], 'avatar' => $g['avatar']],
            'goal' => $this->json($g),
            'at' => $type === 'goal_reached' ? ($g['reached_at'] ?? $g['updated_at']) : ($g['change_requested_at'] ?? $g['created_at']),
            'counted' => true,
        ];
    }

    // ---- internals ---------------------------------------------------------------------------------

    /** Recompute reached/active from the balance after anything that changes the balance or the price. */
    public function syncReached(int $householdId, int $goalId): void
    {
        $g = $this->find($householdId, $goalId);
        $balance = $this->ledger->goalBalance($goalId);
        $now = $this->clock->nowSql();
        if ($g['status'] === 'active' && $balance >= (int) $g['target']) {
            $this->pdo->prepare("UPDATE goals SET status = 'reached', reached_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $goalId]);
        } elseif ($g['status'] === 'reached' && $balance < (int) $g['target']) {
            $this->pdo->prepare("UPDATE goals SET status = 'active', reached_at = NULL, updated_at = ? WHERE id = ?")->execute([$now, $goalId]);
        }
    }

    private function applyEdits(array $g, array $in): void
    {
        $name = array_key_exists('name', $in) ? $this->name($in['name']) : $g['name'];
        $target = array_key_exists('target', $in) ? $this->target($in['target']) : (int) $g['target'];
        $link = array_key_exists('link', $in) ? Links::clean($in['link']) : $g['link'];
        $this->pdo->prepare('UPDATE goals SET name = ?, target = ?, link = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $target, $link, $this->clock->nowSql(), $g['id']]);
    }

    private function clearPending(int $goalId): void
    {
        $this->pdo->prepare('UPDATE goals SET pending_link = NULL, pending_link_set = 0, pending_target = NULL, change_requested_at = NULL WHERE id = ?')->execute([$goalId]);
    }

    private function requireFunded(array $g): void
    {
        if (!in_array($g['status'], ['active', 'reached'], true)) {
            throw new ApiException(409, $g['status'] === 'requested' ? 'A parent needs to approve this goal first.' : "That goal isn't open.");
        }
    }

    private function requireRoom(int $householdId, int $kidId, bool $byParent): void
    {
        $plan = $this->pdo->prepare('SELECT plan FROM households WHERE id = ?');
        $plan->execute([$householdId]);
        $max = Plans::maxActiveGoals((string) $plan->fetchColumn());
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM goals WHERE kid_id = ? AND status IN ('requested', 'active', 'reached')");
        $count->execute([$kidId]);
        if ((int) $count->fetchColumn() >= $max) {
            throw new ApiException(409, $byParent
                ? "This kid already has an active goal. The free plan allows one at a time, so finish or cancel it first."
                : "You can save for one goal at a time right now. Finish or cancel your current goal first.");
        }
    }

    private function name(mixed $v): string
    {
        $name = is_string($v) ? trim($v) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException(422, 'Give the goal a name (up to 100 characters).');
        }
        return $name;
    }

    private function currency(mixed $v): string
    {
        if (!in_array($v, ['points', 'money'], true)) {
            throw new ApiException(422, 'Choose points or money.');
        }
        return $v;
    }

    private function target(mixed $v): int
    {
        if (!is_int($v) || $v < 1 || $v > self::MAX_TARGET) {
            throw new ApiException(422, 'Enter a price greater than zero.');
        }
        return $v;
    }

    private function note(?string $note): ?string
    {
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 200) {
            throw new ApiException(422, 'Keep the note under 200 characters.');
        }
        return $note === '' ? null : $note;
    }
}
