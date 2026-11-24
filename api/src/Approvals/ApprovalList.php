<?php
declare(strict_types=1);

namespace App\Approvals;

use App\Chores\InstanceService;
use App\Money\ChallengeService;
use App\Money\GoalService;
use App\Money\RewardService;

/**
 * The parent's "needs your approval" list: chores to check, goal requests and changes, reached goals, reward requests
 * and deliveries, and earned challenges. The badge counts the items that need a parent's action; "waiting day" lines
 * are summaries of chores that are already counted, so they aren't counted again.
 */
final class ApprovalList
{
    public function __construct(
        private InstanceService $instances,
        private GoalService $goals,
        private RewardService $rewards,
        private ChallengeService $challenges,
    ) {}

    /** @return array{count:int, items:list<array>} */
    public function forHousehold(int $householdId): array
    {
        $chores = $this->instances->approvals($householdId)['items'];
        $actionable = [];
        $summaries = [];
        foreach ($chores as $item) {
            if ($item['type'] === 'chore') {
                $item['at'] = $item['reported_at'];
                $actionable[] = $item;
            } else {
                $summaries[] = $item; // day_waiting
            }
        }
        $items = array_merge(
            $actionable,
            $this->goals->approvalItems($householdId),
            $this->rewards->approvalItems($householdId),
            $this->challenges->approvalItems($householdId),
        );
        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at'])); // newest first
        $items = array_merge($items, $summaries);
        return ['count' => count(array_filter($items, fn ($i) => $i['counted'])), 'items' => $items];
    }

    public function count(int $householdId): int
    {
        return $this->forHousehold($householdId)['count'];
    }
}
