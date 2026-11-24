<?php
declare(strict_types=1);

namespace App\Money;

use App\Clock;
use App\Http\ApiException;
use PDO;

/** Savings % and Giving % per kid, per currency, and the split taken off the top when earnings unlock. */
final class Buckets
{
    public function __construct(private PDO $pdo, private Clock $clock) {}

    /** @return array{savings_pct:int, giving_pct:int} (0/0 when nothing is set) */
    public function percentages(int $kidId, string $currency): array
    {
        $stmt = $this->pdo->prepare('SELECT savings_pct, giving_pct FROM bucket_settings WHERE kid_id = ? AND currency = ?');
        $stmt->execute([$kidId, $currency]);
        $row = $stmt->fetch();
        return $row ? ['savings_pct' => (int) $row['savings_pct'], 'giving_pct' => (int) $row['giving_pct']] : ['savings_pct' => 0, 'giving_pct' => 0];
    }

    /** Both currencies, for the parent's kid settings. */
    public function all(int $kidId): array
    {
        return ['points' => $this->percentages($kidId, 'points'), 'money' => $this->percentages($kidId, 'money')];
    }

    public function set(int $kidId, string $currency, int $savings, int $giving): void
    {
        if (!in_array($currency, ['points', 'money'], true)) {
            throw new ApiException(422, 'Choose points or money.');
        }
        if ($savings < 0 || $giving < 0 || $savings > 100 || $giving > 100 || $savings + $giving > 100) {
            throw new ApiException(422, 'Savings and Giving must be between 0 and 100 percent, and together no more than 100.');
        }
        if ($savings === 0 && $giving === 0) {
            // Nothing set: no row, so the currency doesn't appear on the kid's screens just because of this.
            $this->pdo->prepare('DELETE FROM bucket_settings WHERE kid_id = ? AND currency = ?')->execute([$kidId, $currency]);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO bucket_settings (kid_id, currency, savings_pct, giving_pct, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE savings_pct = VALUES(savings_pct), giving_pct = VALUES(giving_pct), updated_at = VALUES(updated_at)'
        )->execute([$kidId, $currency, $savings, $giving, $this->clock->nowSql()]);
    }

    /**
     * Split an amount (whole points, or cents) that is being unlocked. Each share is rounded DOWN to a whole unit;
     * whatever is left over (including rounding) goes to Spending.
     * @return array{savings:int, giving:int, spending:int}
     */
    public function split(int $kidId, string $currency, int $amount): array
    {
        $p = $this->percentages($kidId, $currency);
        $savings = intdiv($amount * $p['savings_pct'], 100);
        $giving = intdiv($amount * $p['giving_pct'], 100);
        return ['savings' => $savings, 'giving' => $giving, 'spending' => $amount - $savings - $giving];
    }
}
