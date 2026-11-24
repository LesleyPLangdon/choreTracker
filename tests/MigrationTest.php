<?php
declare(strict_types=1);

namespace Tests;

use App\Migrator;

final class MigrationTest extends ApiTestCase
{
    public function testAllTablesExistAndRerunIsHarmless(): void
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['households', 'parents', 'parent_invites', 'kids', 'auth_tokens', 'chores', 'chore_slots', 'chore_instances',
                  'kid_days', 'ledger_entries', 'bucket_settings', 'goals', 'rewards', 'reward_requests',
                  'challenges', 'challenge_chores', 'challenge_periods', 'day_events', 'reward_kids', 'schema_migrations'] as $t) {
            $this->assertContains($t, $tables, $t);
        }
        $this->assertSame([], (new Migrator($this->pdo, dirname(__DIR__) . '/db/migrations'))->run());
    }

    public function testOneInstancePerSlotPerDate(): void
    {
        [$token, $body] = $this->registerParent();
        [$kidId] = $this->createKid($token);
        $hid = $body['household']['id'];
        $now = $this->clock->nowSql();
        $this->pdo->prepare("INSERT INTO chores (household_id, kid_id, group_id, title, created_at, updated_at) VALUES (?, ?, 'g1', 'Walk dog', ?, ?)")->execute([$hid, $kidId, $now, $now]);
        $choreId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO chore_slots (chore_id, label) VALUES (?, 'Morning')")->execute([$choreId]);
        $slotId = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare('INSERT INTO chore_instances (household_id, chore_id, slot_id, kid_id, local_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$hid, $choreId, $slotId, $kidId, '2026-12-01', $now, $now]);
        $insert->execute([$hid, $choreId, $slotId, $kidId, '2026-12-02', $now, $now]);
        $this->expectException(\PDOException::class);
        $insert->execute([$hid, $choreId, $slotId, $kidId, '2026-12-01', $now, $now]);
    }
}
