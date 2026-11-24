<?php
declare(strict_types=1);

namespace Tests;

use App\Database;
use App\Rules\Settler;
use App\Services;

/**
 * Base for the Phase 3 tests. The household is created on Monday 2026-11-30 (10:00 AM in New York) so chores made
 * "yesterday" count as required from Tuesday 2026-12-01. Use $this->clock->set(self::DEC1) etc. to move around.
 * All times below are 10:00 AM in New York (15:00 UTC); a New York day ends at 05:00 UTC the next day.
 */
abstract class RulesTestCase extends ApiTestCase
{
    protected const NOV30 = '2026-11-30 15:00:00';
    protected const DEC1 = '2026-12-01 15:00:00';
    protected const DEC2 = '2026-12-02 15:00:00';
    protected const DEC3 = '2026-12-03 15:00:00';
    protected const DEC4 = '2026-12-04 15:00:00';
    protected const DEC5 = '2026-12-05 15:00:00';

    protected string $parent;
    protected int $kidId;
    protected string $kid;
    protected int $householdId;
    protected string $kidLink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set(self::NOV30);
        [$this->parent, $reg] = $this->registerParent();
        $this->householdId = $reg['household']['id'];
        [$this->kidId, $this->kidLink] = $this->createKid($this->parent);
        $this->kid = $this->kidToken($this->kidLink);
    }

    /** Ledger invariants that must hold after EVERY test: nothing is ever negative, per kid/currency/bucket or per day. */
    protected function tearDown(): void
    {
        $rows = $this->pdo->query(
            "SELECT kid_id, currency, bucket, SUM(amount) AS total FROM ledger_entries GROUP BY kid_id, currency, bucket"
        )->fetchAll();
        foreach ($rows as $r) {
            $this->assertGreaterThanOrEqual(0, (int) $r['total'], "negative {$r['bucket']} balance for kid {$r['kid_id']} ({$r['currency']})");
        }
        $days = $this->pdo->query(
            "SELECT kid_id, currency, local_date, SUM(amount) AS total FROM ledger_entries
             WHERE bucket = 'pending' AND local_date IS NOT NULL GROUP BY kid_id, currency, local_date"
        )->fetchAll();
        foreach ($days as $d) {
            $this->assertGreaterThanOrEqual(0, (int) $d['total'], "negative pending on {$d['local_date']}");
        }
        foreach ($this->pdo->query("SELECT goal_id, SUM(amount) AS total FROM ledger_entries WHERE bucket = 'goal' GROUP BY goal_id")->fetchAll() as $g) {
            $this->assertGreaterThanOrEqual(0, (int) $g['total'], "negative balance in goal {$g['goal_id']}");
        }
        parent::tearDown();
    }

    /** Make a chore for the default kid. */
    protected function chore(array $fields = []): int
    {
        return $this->createChore($this->parent, [$this->kidId], $fields)[0];
    }

    /** A row on the kid's list for today (by title, and slot label for multi-slot chores). */
    protected function row(string $title, ?string $slot = null, ?array $day = null): array
    {
        $day ??= $this->kidList($this->kid);
        foreach ($day['chores'] as $c) {
            if ($c['title'] === $title && ($slot === null || $c['slot_label'] === $slot)) {
                return $c;
            }
        }
        $this->fail("No row '$title'" . ($slot ? " / $slot" : '') . ' on the list: ' . implode(', ', $this->titles($day)));
    }

    protected function done(int $id): array
    {
        return $this->call('POST', "/kid/instances/$id/done", [], $this->kid);
    }

    protected function approve(int $id): array
    {
        $r = $this->call('POST', "/instances/$id/approve", [], $this->parent);
        $this->assertSame(200, $r[0], json_encode($r[1]));
        return $r;
    }

    protected function sendBack(int $id): array
    {
        $r = $this->call('POST', "/instances/$id/send-back", [], $this->parent);
        $this->assertSame(200, $r[0], json_encode($r[1]));
        return $r;
    }

    /** Do and approve a chore in one go (kid taps Done, parent approves). */
    protected function doAndApprove(string $title, ?string $slot = null): void
    {
        $id = $this->row($title, $slot)['id'];
        $this->assertSame(200, $this->done($id)[0]);
        $this->approve($id);
    }

    /** Sum of the ledger by bucket for one kid + currency, straight from the database (not via App\Ledger). */
    protected function sums(string $currency = 'points', ?int $kidId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT bucket, SUM(amount) FROM ledger_entries WHERE kid_id = ? AND currency = ? GROUP BY bucket');
        $stmt->execute([$kidId ?? $this->kidId, $currency]);
        $out = ['pending' => 0, 'spending' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$bucket, $sum]) {
            $out[$bucket] = (int) $sum;
        }
        return $out;
    }

    protected function assertLedger(int $pending, int $spending, string $currency = 'points', string $message = ''): void
    {
        $this->assertSame(['pending' => $pending, 'spending' => $spending], $this->sums($currency), $message ?: "ledger ($currency)");
    }

    protected function dayStatus(string $day, ?int $kidId = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM kid_days WHERE kid_id = ? AND local_date = ?');
        $stmt->execute([$kidId ?? $this->kidId, $day]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    protected function instanceStatuses(string $title): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.local_date, i.status FROM chore_instances i JOIN chores c ON c.id = i.chore_id WHERE c.title = ? ORDER BY i.local_date, i.id'
        );
        $stmt->execute([$title]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    protected function entryCount(string $type): int
    {
        return $this->countRows("SELECT COUNT(*) FROM ledger_entries WHERE entry_type = '$type'");
    }

    /** A settler wired to a given connection (to model a second request on its own connection). */
    protected function settlerFor(\PDO $pdo): Settler
    {
        return (new Services($pdo, $this->clock))->settler;
    }

    protected function otherConnection(): \PDO
    {
        return Database::connect(test_config());
    }

    /** The parent's approvals list. */
    protected function approvals(): array
    {
        [$status, $body] = $this->call('GET', '/approvals', [], $this->parent);
        $this->assertSame(200, $status);
        return $body;
    }
}
