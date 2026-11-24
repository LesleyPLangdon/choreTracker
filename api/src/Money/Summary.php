<?php
declare(strict_types=1);

namespace App\Money;

use App\Ledger;
use PDO;

/** What a kid's home screen shows about money: only the currencies the kid uses, and only what exists. */
final class Summary
{
    public function __construct(
        private PDO $pdo,
        private Ledger $ledger,
        private Currencies $currencies,
        private Buckets $buckets,
        private GoalService $goals,
        private RewardService $rewards,
    ) {}

    public function forKid(int $householdId, int $kidId): array
    {
        $in = $this->currencies->inUse($kidId);
        $spending = [];
        $savings = [];
        $giving = [];
        foreach ($in as $currency) {
            $spending[$currency] = $this->ledger->balance($kidId, $currency, 'spending');
            $pct = $this->buckets->percentages($kidId, $currency);
            $save = $this->ledger->balance($kidId, $currency, 'savings');
            $give = $this->ledger->balance($kidId, $currency, 'giving');
            // Savings and Giving rows only appear once they're in play (a percentage is set, or there is something in them).
            if ($pct['savings_pct'] > 0 || $save !== 0) {
                $savings[$currency] = $save;
            }
            if ($pct['giving_pct'] > 0 || $give !== 0) {
                $giving[$currency] = $give;
            }
        }
        $challenges = $this->pdo->prepare('SELECT COUNT(*) FROM challenges WHERE kid_id = ? AND household_id = ? AND active = 1');
        $challenges->execute([$kidId, $householdId]);
        $live = array_values(array_filter($this->goals->forKid($kidId), fn ($g) => in_array($g['status'], ['requested', 'active', 'reached'], true)));
        return [
            'currencies' => $in,
            'spending' => $spending,
            'savings' => $savings,
            'giving' => $giving,
            'goals' => $live,
            'has_rewards' => count($this->rewards->forKid($householdId, $kidId)) > 0,
            'has_challenges' => (int) $challenges->fetchColumn() > 0,
        ];
    }
}
