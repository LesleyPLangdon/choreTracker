<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * The ledger is APPEND-ONLY: this class can add entries and read balances, and nothing else.
 * There is no update or delete. A mistake is fixed by appending an offsetting entry.
 * Balances are always computed from the entries, never stored.
 */
final class Ledger
{
    public const CURRENCIES = ['points', 'money'];
    public const BUCKETS = ['pending', 'spending', 'savings', 'giving', 'goal'];
    public const TYPES = ['earning', 'unlock', 'bucket_split', 'forfeit', 'goal_transfer',
        'reward_redemption', 'payout_transfer', 'parent_adjustment'];

    public function __construct(private PDO $pdo, private Clock $clock) {}

    /**
     * @param array{chore_instance_id?:int, goal_id?:int, reward_request_id?:int, local_date?:string,
     *              created_by_type?:string, created_by_id?:int, note?:string} $ref
     */
    public function append(int $householdId, int $kidId, string $currency, string $bucket, string $type, int $amount, array $ref = []): int
    {
        if (!in_array($currency, self::CURRENCIES, true) || !in_array($bucket, self::BUCKETS, true) || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid ledger currency, bucket, or entry type.');
        }
        $this->pdo->prepare(
            'INSERT INTO ledger_entries (household_id, kid_id, currency, bucket, entry_type, amount, local_date,
               chore_instance_id, goal_id, reward_request_id, created_by_type, created_by_id, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $householdId, $kidId, $currency, $bucket, $type, $amount, $ref['local_date'] ?? null,
            $ref['chore_instance_id'] ?? null, $ref['goal_id'] ?? null, $ref['reward_request_id'] ?? null,
            $ref['created_by_type'] ?? 'system', $ref['created_by_id'] ?? null, $ref['note'] ?? null,
            $this->clock->nowSql(),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Balance of one bucket in one currency (whole points, or cents). */
    public function balance(int $kidId, string $currency, string $bucket): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE kid_id = ? AND currency = ? AND bucket = ?');
        $stmt->execute([$kidId, $currency, $bucket]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> bucket => balance for one currency */
    public function balances(int $kidId, string $currency): array
    {
        $out = array_fill_keys(self::BUCKETS, 0);
        $stmt = $this->pdo->prepare('SELECT bucket, SUM(amount) AS total FROM ledger_entries WHERE kid_id = ? AND currency = ? GROUP BY bucket');
        $stmt->execute([$kidId, $currency]);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['bucket']] = (int) $row['total'];
        }
        return $out;
    }
}
