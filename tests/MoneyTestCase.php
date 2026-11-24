<?php
declare(strict_types=1);

namespace Tests;

/** Base for the Phase 4 (money flow) tests: helpers for buckets, funding Spending, and independent ledger checks. */
abstract class MoneyTestCase extends RulesTestCase
{
    protected function kidCall(string $method, string $path, array $body = []): array
    {
        return $this->call($method, $path, $body, $this->kid);
    }

    protected function parentCall(string $method, string $path, array $body = []): array
    {
        return $this->call($method, $path, $body, $this->parent);
    }

    protected function ok(array $response, int $status = 200): array
    {
        $this->assertSame($status, $response[0], json_encode($response[1]));
        return $response[1];
    }

    protected function setBuckets(string $currency, int $savings, int $giving, ?int $kidId = null): void
    {
        $this->ok($this->parentCall('PUT', '/kids/' . ($kidId ?? $this->kidId) . '/buckets', [$currency => ['savings_pct' => $savings, 'giving_pct' => $giving]]));
    }

    /** Put money straight into Spending (a parent adjustment), to set up a test. */
    protected function fund(string $currency, int $amount, ?int $kidId = null, ?string $bucket = null): void
    {
        $this->ok($this->parentCall('POST', '/kids/' . ($kidId ?? $this->kidId) . '/adjust', [
            'currency' => $currency, 'amount' => $amount, 'note' => 'test setup', 'bucket' => $bucket ?? 'spending',
        ]));
    }

    /** Every bucket's sum for a kid + currency, straight from the ledger (not via App\Ledger). */
    protected function buckets(string $currency = 'points', ?int $kidId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT bucket, SUM(amount) FROM ledger_entries WHERE kid_id = ? AND currency = ? GROUP BY bucket');
        $stmt->execute([$kidId ?? $this->kidId, $currency]);
        $out = ['pending' => 0, 'spending' => 0, 'savings' => 0, 'giving' => 0, 'goal' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$bucket, $sum]) {
            $out[$bucket] = (int) $sum;
        }
        return $out;
    }

    protected function assertBuckets(array $expected, string $currency = 'points', string $message = ''): void
    {
        $this->assertSame(array_merge(['pending' => 0, 'spending' => 0, 'savings' => 0, 'giving' => 0, 'goal' => 0], $expected), $this->buckets($currency), $message ?: "buckets ($currency)");
    }

    protected function summary(): array
    {
        return $this->ok($this->kidCall('GET', '/kid/summary'))['summary'];
    }

    /** Create a goal as a parent (already approved) and return it. */
    protected function parentGoal(string $name, string $currency, int $target, ?string $link = null): array
    {
        return $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/goals", ['name' => $name, 'currency' => $currency, 'target' => $target] + ($link ? ['link' => $link] : [])), 201)['goal'];
    }

    protected function moveToGoal(int $goalId, int $amount): array
    {
        return $this->kidCall('POST', "/kid/goals/$goalId/move", ['action' => 'in', 'amount' => $amount]);
    }

    protected function activityText(): array
    {
        return array_column($this->ok($this->parentCall('GET', "/kids/{$this->kidId}/activity"))['activity'], 'text');
    }
}
