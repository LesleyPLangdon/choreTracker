<?php
declare(strict_types=1);

namespace App;

use App\HouseholdTime;
use App\Support\Tokens;
use PDO;

/** Demo data for the TEST SITE ONLY. Every public method checks TestSiteGuard first. */
final class Seeder
{
    public const PARENT_PASSWORD = 'Correct-Horse-42';
    public const KID_PIN = '1234';

    public function __construct(private PDO $pdo, private Config $config, private Clock $clock) {}

    /** Wipe all app data (keeps the schema and migration history). */
    public function reset(): void
    {
        TestSiteGuard::assert($this->config);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table !== 'schema_migrations') {
                $this->pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Create the demo household. Safe to repeat: does nothing if the demo parent already exists.
     * @return array{created:bool, kid_links:array<string,string>}  kid nickname => link token (only when created)
     */
    public function seed(): array
    {
        TestSiteGuard::assert($this->config);
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM parents WHERE email = ?');
        $exists->execute(['test@example.com']);
        if ((int) $exists->fetchColumn() > 0) {
            return ['created' => false, 'kid_links' => []];
        }
        $now = $this->clock->nowSql();
        // A paid plan, so the demo kid can have several goals at once (moving money between goals can be tried).
        $this->pdo->prepare('INSERT INTO households (name, timezone, day_end_time, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['Demo Family', 'America/New_York', '00:00:00', 'plus', $now, $now]);
        $householdId = (int) $this->pdo->lastInsertId();
        $parent = $this->pdo->prepare('INSERT INTO parents (household_id, email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?)');
        $parent->execute([$householdId, 'test@example.com', password_hash(self::PARENT_PASSWORD, PASSWORD_DEFAULT), 'Test Parent', $now]);
        $parent->execute([$householdId, 'parent2@example.com', password_hash(self::PARENT_PASSWORD, PASSWORD_DEFAULT), 'Second Parent', $now]);

        $links = [];
        $kidIds = [];
        $kid = $this->pdo->prepare(
            'INSERT INTO kids (household_id, nickname, avatar, pin_hash, link_token_hash, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        foreach ([['Alex', '🦊'], ['Sam', '🐼']] as [$nickname, $avatar]) {
            $token = Tokens::generate('kl_');
            $kid->execute([$householdId, $nickname, $avatar, password_hash(self::KID_PIN, PASSWORD_DEFAULT), Tokens::hash($token), $now, $now]);
            $links[$nickname] = $token;
            $kidIds[] = (int) $this->pdo->lastInsertId();
        }
        $parentId = (int) $this->pdo->query('SELECT id FROM parents ORDER BY id LIMIT 1')->fetchColumn();
        $this->seedChores($householdId, $kidIds, $parentId);
        return ['created' => true, 'kid_links' => $links];
    }

    /**
     * Demo chores and money. Kid 1 (Alex) uses points AND money; kid 2 (Sam) uses points only. Everyday chores are for
     * both kids; the money jobs and the challenge chores are Alex's.
     */
    private function seedChores(int $householdId, array $kidIds, int $parentId): void
    {
        $sv = new Services($this->pdo, $this->clock);
        $chores = $sv->chores;
        [$alex, $sam] = $kidIds;
        $time = $chores->householdTime($householdId);
        // The coming Sunday (today, if today is Sunday).
        $today = $time->today();
        $untilSunday = (7 - HouseholdTime::isoWeekday($today)) % 7;
        $sunday = (new \DateTimeImmutable($today))->modify("+$untilSunday days")->format('Y-m-d');

        $both = ['kid_ids' => $kidIds];
        $alexOnly = ['kid_ids' => [$alex]];
        $make = fn (array $chore, array $who) => $chores->create($householdId, $chore + $who);
        $make(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true], $both);
        $make(['title' => 'Brush teeth', 'required' => true, 'slots' => [
            ['label' => 'Morning', 'due_by' => '08:00'],
            ['label' => 'Evening', 'available_from' => '18:00'],
        ]], $both);
        $make(['title' => 'Feed the fish', 'value' => 5, 'currency' => 'points', 'auto_approve' => true], $both);
        $make(['title' => 'Walk the dog', 'value' => 50, 'currency' => 'money', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]], $alexOnly);
        $make(['title' => 'Rake the front yard', 'schedule_type' => 'one_off', 'due_date' => $sunday, 'value' => 500, 'currency' => 'money'], $alexOnly);
        $make(['title' => 'Get dressed', 'days' => [1, 2, 3, 4, 5]], $alexOnly);
        $make(['title' => 'Pack backpack', 'days' => [1, 2, 3, 4, 5]], $alexOnly);

        // Alex: 10% Savings and 10% Giving on money; nothing on points.
        $sv->buckets->set($alex, 'money', 10, 10);

        // Rewards (everyone can see them).
        foreach ([['Pick the movie', 50], ['Stay up 30 minutes late', 100], ['Ice cream trip', 200]] as [$name, $cost]) {
            $sv->rewards->create($householdId, ['name' => $name, 'cost' => $cost, 'currency' => 'points']);
        }

        // Alex's approved goals (the household is on a plan that allows several).
        $sv->goals->create($householdId, $alex, ['name' => 'New game', 'currency' => 'money', 'target' => 2000], $parentId);
        $sv->goals->create($householdId, $alex, ['name' => 'Baseball bat', 'currency' => 'money', 'target' => 4500,
            'link' => 'https://www.dickssportinggoods.com/p/example-baseball-bat'], $parentId);

        // The two example challenges, for Alex.
        $group = function (string $title) {
            $stmt = $this->pdo->prepare('SELECT group_id FROM chores WHERE title = ? ORDER BY id LIMIT 1');
            $stmt->execute([$title]);
            return (string) $stmt->fetchColumn();
        };
        $sv->challenges->create($householdId, [
            'kid_id' => $alex, 'reward_name' => 'Open gym', 'target_count' => 7, 'chore_group_ids' => [$group('Walk the dog')],
            'repeat_type' => 'weekly', 'start_weekday' => 5, 'start_time' => '17:00', 'end_weekday' => 5, 'end_time' => '17:00',
        ]);
        $sv->challenges->create($householdId, [
            'kid_id' => $alex, 'reward_name' => '30 minutes of TV after school', 'target_count' => 3,
            'chore_group_ids' => [$group('Get dressed'), $group('Brush teeth'), $group('Pack backpack')],
            'repeat_type' => 'daily', 'days' => [1, 2, 3, 4, 5], 'start_time' => '06:00', 'end_time' => '07:30',
        ]);
    }
}
