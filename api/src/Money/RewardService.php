<?php
declare(strict_types=1);

namespace App\Money;

use App\Clock;
use App\Http\ApiException;
use App\Ledger;
use PDO;

/**
 * Rewards the parent offers, and the kid's request → approve → deliver flow. The price comes out of Spending when a
 * parent approves (or out of the reward's own goal first, if the kid saved for it). Nothing is taken at request time.
 */
final class RewardService
{
    private const MAX_PRICE = ['points' => 1000000, 'money' => 10000000];

    public function __construct(private PDO $pdo, private Clock $clock, private Ledger $ledger, private GoalService $goals) {}

    // ---- the parent's rewards -------------------------------------------------------------------

    public function listForParent(int $householdId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rewards WHERE household_id = ? AND active = 1 ORDER BY currency DESC, cost, name');
        $stmt->execute([$householdId]);
        return array_map(fn ($r) => $this->json($r), $stmt->fetchAll());
    }

    public function create(int $householdId, array $in): array
    {
        [$name, $cost, $currency, $kidIds] = $this->clean($householdId, $in);
        $now = $this->clock->nowSql();
        $this->pdo->prepare('INSERT INTO rewards (household_id, name, currency, cost, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$householdId, $name, $currency, $cost, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->setKids($id, $kidIds);
        return $this->json($this->find($householdId, $id));
    }

    public function update(int $householdId, int $rewardId, array $in): array
    {
        $this->find($householdId, $rewardId);
        [$name, $cost, $currency, $kidIds] = $this->clean($householdId, $in);
        $this->pdo->prepare('UPDATE rewards SET name = ?, currency = ?, cost = ?, updated_at = ? WHERE id = ?')->execute([$name, $currency, $cost, $this->clock->nowSql(), $rewardId]);
        $this->setKids($rewardId, $kidIds);
        return $this->json($this->find($householdId, $rewardId));
    }

    public function remove(int $householdId, int $rewardId): void
    {
        $this->find($householdId, $rewardId);
        $this->pdo->prepare('UPDATE rewards SET active = 0, updated_at = ? WHERE id = ?')->execute([$this->clock->nowSql(), $rewardId]);
    }

    // ---- what a kid sees -------------------------------------------------------------------------

    /** The active rewards this kid can see, with the price, whether they can afford it, and any goal saving for it. */
    public function forKid(int $householdId, int $kidId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.* FROM rewards r
             WHERE r.household_id = ? AND r.active = 1
               AND (NOT EXISTS (SELECT 1 FROM reward_kids rk WHERE rk.reward_id = r.id)
                    OR EXISTS (SELECT 1 FROM reward_kids rk WHERE rk.reward_id = r.id AND rk.kid_id = ?))
             ORDER BY r.currency DESC, r.cost, r.name"
        );
        $stmt->execute([$householdId, $kidId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $spending = $this->ledger->balance($kidId, $r['currency'], 'spending');
            $goal = $this->linkedGoal($kidId, (int) $r['id'], false);
            $fromGoal = ($goal && $goal['status'] === 'reached') ? min($this->ledger->goalBalance((int) $goal['id']), (int) $r['cost']) : 0;
            $pending = $this->pdo->prepare("SELECT id FROM reward_requests WHERE reward_id = ? AND kid_id = ? AND status = 'requested'");
            $pending->execute([$r['id'], $kidId]);
            $requestId = $pending->fetchColumn();
            $affordable = $spending + $fromGoal >= (int) $r['cost'];
            $row = $this->json($r) + [
                'affordable' => $affordable,
                'spending' => $spending,
                'goal' => $goal ? $this->goals->json($goal) : null,
                'request_id' => $requestId !== false ? (int) $requestId : null,
            ];
            $row['can_save'] = !$affordable && !$goal; // "Save for this" when it costs more than the kid has
            $out[] = $row;
        }
        return $out;
    }

    /** A kid's requests that are still open or recently decided. */
    public function requestsForKid(int $kidId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT q.*, r.name FROM reward_requests q JOIN rewards r ON r.id = q.reward_id
             WHERE q.kid_id = ? AND q.status IN ('requested', 'approved', 'denied') ORDER BY q.id DESC LIMIT 20"
        );
        $stmt->execute([$kidId]);
        return array_map(fn ($q) => $this->requestJson($q), $stmt->fetchAll());
    }

    // ---- the kid's actions -----------------------------------------------------------------------

    public function request(int $householdId, int $kidId, int $rewardId): array
    {
        return Locks::kid($this->pdo, $kidId, function () use ($householdId, $kidId, $rewardId) {
            $reward = $this->visibleReward($householdId, $kidId, $rewardId);
            $dup = $this->pdo->prepare("SELECT 1 FROM reward_requests WHERE reward_id = ? AND kid_id = ? AND status = 'requested'");
            $dup->execute([$rewardId, $kidId]);
            if ($dup->fetchColumn()) {
                throw new ApiException(409, 'You already asked for this one. A parent will take a look.');
            }
            $cost = (int) $reward['cost'];
            $available = $this->ledger->balance($kidId, $reward['currency'], 'spending') + $this->goalShare($kidId, $reward);
            if ($available < $cost) {
                throw new ApiException(422, "You don't have enough for this yet. Try \"Save for this\"!");
            }
            $this->pdo->prepare(
                "INSERT INTO reward_requests (household_id, reward_id, kid_id, cost, currency, status, requested_at) VALUES (?, ?, ?, ?, ?, 'requested', ?)"
            )->execute([$householdId, $rewardId, $kidId, $cost, $reward['currency'], $this->clock->nowSql()]);
            return $this->requestJson($this->findRequest($householdId, (int) $this->pdo->lastInsertId()));
        });
    }

    public function cancel(int $householdId, int $kidId, int $requestId): array
    {
        $q = $this->findRequest($householdId, $requestId);
        if ((int) $q['kid_id'] !== $kidId) {
            throw new ApiException(404, 'Request not found.');
        }
        if ($q['status'] !== 'requested') {
            throw new ApiException(409, 'That request has already been decided.');
        }
        $this->pdo->prepare("UPDATE reward_requests SET status = 'cancelled', decided_at = ? WHERE id = ?")->execute([$this->clock->nowSql(), $requestId]);
        return $this->requestJson($this->findRequest($householdId, $requestId));
    }

    /** "Save for this": start a goal for a reward the kid can't afford yet (already approved, linked to the reward). */
    public function saveFor(int $householdId, int $kidId, int $rewardId): array
    {
        $reward = $this->visibleReward($householdId, $kidId, $rewardId);
        if ($this->linkedGoal($kidId, $rewardId, false)) {
            throw new ApiException(409, "You're already saving for this one.");
        }
        return $this->goals->create($householdId, $kidId, [
            'name' => $reward['name'], 'currency' => $reward['currency'], 'target' => (int) $reward['cost'],
        ], null, $rewardId);
    }

    // ---- the parent's decisions --------------------------------------------------------------------

    /**
     * Approve: the price comes out of Spending — or out of the reward's goal first (if it was reached), then Spending.
     * Blocked, with a clear message, if the balance no longer covers it.
     */
    public function approve(int $householdId, int $parentId, int $requestId, ?string $note): array
    {
        $q = $this->findRequest($householdId, $requestId);
        return Locks::kid($this->pdo, (int) $q['kid_id'], function () use ($householdId, $parentId, $requestId, $note) {
            $stmt = $this->pdo->prepare('SELECT * FROM reward_requests WHERE id = ? AND household_id = ? FOR UPDATE');
            $stmt->execute([$requestId, $householdId]);
            $q = $stmt->fetch();
            if (!$q || $q['status'] !== 'requested') {
                throw new ApiException(409, "That request isn't waiting for approval.");
            }
            $kidId = (int) $q['kid_id'];
            $cost = (int) $q['cost'];
            $currency = $q['currency'];
            $by = ['created_by_type' => 'parent', 'created_by_id' => $parentId];

            $goal = $this->linkedGoal($kidId, (int) $q['reward_id'], true);
            $fromGoal = $goal ? min($this->ledger->goalBalance((int) $goal['id']), $cost) : 0;
            $fromSpending = $cost - $fromGoal;
            if ($this->ledger->balance($kidId, $currency, 'spending') < $fromSpending) {
                throw new ApiException(409, "Spending no longer covers this reward. Adjust their balance or deny the request.");
            }
            if ($goal) {
                // The goal pays first (and is finished); anything beyond the price goes back to Spending.
                $this->goals->consume($householdId, $kidId, $goal, $cost, 'reward_paid', $by, $requestId, 'reward_redemption');
                $now = $this->clock->nowSql();
                $this->pdo->prepare("UPDATE goals SET status = 'used', used_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $goal['id']]);
            }
            if ($fromSpending > 0) {
                $this->ledger->append($householdId, $kidId, $currency, 'spending', 'reward_redemption', -$fromSpending, $by + [
                    'reward_request_id' => $requestId, 'batch' => $this->ledger->newBatch(), 'action' => 'reward_paid',
                ]);
            }
            $this->pdo->prepare("UPDATE reward_requests SET status = 'approved', approved_by_parent_id = ?, decided_at = ?, decision_note = ? WHERE id = ?")
                ->execute([$parentId, $this->clock->nowSql(), $this->note($note), $requestId]);
            return $this->requestJson($this->findRequest($householdId, $requestId));
        });
    }

    public function deny(int $householdId, int $parentId, int $requestId, ?string $note): array
    {
        $q = $this->findRequest($householdId, $requestId);
        if ($q['status'] !== 'requested') {
            throw new ApiException(409, "That request isn't waiting for approval.");
        }
        $this->pdo->prepare("UPDATE reward_requests SET status = 'denied', approved_by_parent_id = ?, decided_at = ?, decision_note = ? WHERE id = ?")
            ->execute([$parentId, $this->clock->nowSql(), $this->note($note), $requestId]);
        return $this->requestJson($this->findRequest($householdId, $requestId));
    }

    public function delivered(int $householdId, int $requestId): array
    {
        $q = $this->findRequest($householdId, $requestId);
        if ($q['status'] !== 'approved') {
            throw new ApiException(409, "That reward hasn't been approved yet.");
        }
        $this->pdo->prepare("UPDATE reward_requests SET status = 'delivered', delivered_at = ? WHERE id = ?")->execute([$this->clock->nowSql(), $requestId]);
        return $this->requestJson($this->findRequest($householdId, $requestId));
    }

    // ---- approvals list ---------------------------------------------------------------------------

    /** Requests to decide, and approved rewards still to hand over. */
    public function approvalItems(int $householdId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT q.*, r.name, k.nickname, k.avatar FROM reward_requests q
             JOIN rewards r ON r.id = q.reward_id JOIN kids k ON k.id = q.kid_id
             WHERE q.household_id = ? AND q.status IN ('requested', 'approved') ORDER BY COALESCE(q.decided_at, q.requested_at) DESC, q.id DESC"
        );
        $stmt->execute([$householdId]);
        return array_map(fn ($q) => [
            'type' => $q['status'] === 'requested' ? 'reward_request' : 'reward_delivery',
            'id' => (int) $q['id'],
            'kid' => ['id' => (int) $q['kid_id'], 'nickname' => $q['nickname'], 'avatar' => $q['avatar']],
            'request' => $this->requestJson($q),
            'at' => $q['decided_at'] ?? $q['requested_at'],
            'counted' => true,
        ], $stmt->fetchAll());
    }

    // ---- internals ----------------------------------------------------------------------------------

    public function json(array $r): array
    {
        $kids = $this->pdo->prepare('SELECT kid_id FROM reward_kids WHERE reward_id = ? ORDER BY kid_id');
        $kids->execute([$r['id']]);
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'currency' => $r['currency'], 'cost' => (int) $r['cost'],
            'kid_ids' => array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN)), // empty = every kid
        ];
    }

    private function requestJson(array $q): array
    {
        $name = $q['name'] ?? null;
        if ($name === null) {
            $n = $this->pdo->prepare('SELECT name FROM rewards WHERE id = ?');
            $n->execute([$q['reward_id']]);
            $name = $n->fetchColumn();
        }
        return [
            'id' => (int) $q['id'], 'reward_id' => (int) $q['reward_id'], 'kid_id' => (int) $q['kid_id'], 'name' => $name,
            'cost' => (int) $q['cost'], 'currency' => $q['currency'], 'status' => $q['status'],
            'decision_note' => $q['decision_note'], 'requested_at' => $q['requested_at'],
        ];
    }

    private function find(int $householdId, int $rewardId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rewards WHERE id = ? AND household_id = ? AND active = 1');
        $stmt->execute([$rewardId, $householdId]);
        $r = $stmt->fetch();
        if (!$r) {
            throw new ApiException(404, 'Reward not found.');
        }
        return $r;
    }

    private function visibleReward(int $householdId, int $kidId, int $rewardId): array
    {
        foreach ($this->forKid($householdId, $kidId) as $r) {
            if ($r['id'] === $rewardId) {
                return $this->find($householdId, $rewardId);
            }
        }
        throw new ApiException(404, 'Reward not found.');
    }

    private function findRequest(int $householdId, int $requestId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reward_requests WHERE id = ? AND household_id = ?');
        $stmt->execute([$requestId, $householdId]);
        $q = $stmt->fetch();
        if (!$q) {
            throw new ApiException(404, 'Request not found.');
        }
        return $q;
    }

    /** The kid's goal saving for this reward (newest). With $reachedOnly, only one that has reached its target. */
    private function linkedGoal(int $kidId, int $rewardId, bool $reachedOnly): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM goals WHERE kid_id = ? AND reward_id = ? AND status IN ('active', 'reached') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$kidId, $rewardId]);
        $g = $stmt->fetch();
        if (!$g || ($reachedOnly && $g['status'] !== 'reached')) {
            return null;
        }
        return $g;
    }

    /** How much a reached goal for this reward can put toward its price. */
    private function goalShare(int $kidId, array $reward): int
    {
        $g = $this->linkedGoal($kidId, (int) $reward['id'], true);
        return $g ? min($this->ledger->goalBalance((int) $g['id']), (int) $reward['cost']) : 0;
    }

    /** @return array{0:string,1:int,2:string,3:list<int>} */
    private function clean(int $householdId, array $in): array
    {
        $name = is_string($in['name'] ?? null) ? trim($in['name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException(422, 'Give the reward a name (up to 100 characters).');
        }
        $currency = $in['currency'] ?? 'points';
        if (!in_array($currency, ['points', 'money'], true)) {
            throw new ApiException(422, 'Choose points or money.');
        }
        $cost = $in['cost'] ?? null;
        if (!is_int($cost) || $cost < 1 || $cost > self::MAX_PRICE[$currency]) {
            throw new ApiException(422, 'Enter a price greater than zero.');
        }
        $kidIds = [];
        $check = $this->pdo->prepare('SELECT 1 FROM kids WHERE id = ? AND household_id = ?');
        foreach (($in['kid_ids'] ?? []) as $id) {
            $check->execute([$id, $householdId]);
            if (!is_int($id) || !$check->fetchColumn()) {
                throw new ApiException(422, 'Choose kids from the list.');
            }
            $kidIds[$id] = $id;
        }
        return [$name, $cost, $currency, array_values($kidIds)];
    }

    private function setKids(int $rewardId, array $kidIds): void
    {
        $this->pdo->prepare('DELETE FROM reward_kids WHERE reward_id = ?')->execute([$rewardId]);
        $insert = $this->pdo->prepare('INSERT INTO reward_kids (reward_id, kid_id) VALUES (?, ?)');
        foreach ($kidIds as $id) {
            $insert->execute([$rewardId, $id]);
        }
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
