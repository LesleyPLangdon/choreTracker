<?php
declare(strict_types=1);

namespace App\Money;

use PDO;

/**
 * Which currencies a kid "uses". There is no per-kid currency setting: a currency appears on a kid's screens only if
 * the kid has a chore, any ledger activity, a goal, or a Savings/Giving setting in it. A points-only kid never sees money.
 */
final class Currencies
{
    public function __construct(private PDO $pdo) {}

    /** @return list<string> in display order: points, then money */
    public function inUse(int $kidId): array
    {
        $out = [];
        foreach (['points', 'money'] as $currency) {
            if ($this->uses($kidId, $currency)) {
                $out[] = $currency;
            }
        }
        return $out;
    }

    public function uses(int $kidId, string $currency): bool
    {
        $checks = [
            "SELECT 1 FROM chores WHERE kid_id = ? AND currency = ? AND active = 1 LIMIT 1",
            "SELECT 1 FROM ledger_entries WHERE kid_id = ? AND currency = ? LIMIT 1",
            "SELECT 1 FROM goals WHERE kid_id = ? AND currency = ? AND status IN ('requested', 'active', 'reached', 'used') LIMIT 1",
            "SELECT 1 FROM bucket_settings WHERE kid_id = ? AND currency = ? AND savings_pct + giving_pct > 0 LIMIT 1",
        ];
        foreach ($checks as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$kidId, $currency]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    /** Keep only the in-use currencies of a ['points' => .., 'money' => ..] map. */
    public function only(int $kidId, array $byCurrency): array
    {
        return array_intersect_key($byCurrency, array_flip($this->inUse($kidId)));
    }
}
