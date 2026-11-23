<?php
declare(strict_types=1);

namespace App;

use App\Chores\ChoreService;
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
        $this->pdo->prepare('INSERT INTO households (name, timezone, day_end_time, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['Demo Family', 'America/New_York', '00:00:00', 'free', $now, $now]);
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
        $this->seedChores($householdId, $kidIds);
        return ['created' => true, 'kid_links' => $links];
    }

    /** Demo chores, assigned to both kids, so every Phase 2 feature can be tried right away. */
    private function seedChores(int $householdId, array $kidIds): void
    {
        $chores = new ChoreService($this->pdo, $this->clock);
        $time = $chores->householdTime($householdId);
        // The coming Sunday (today, if today is Sunday).
        $today = $time->today();
        $untilSunday = (7 - HouseholdTime::isoWeekday($today)) % 7;
        $sunday = (new \DateTimeImmutable($today))->modify("+$untilSunday days")->format('Y-m-d');

        $demo = [
            ['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true],
            ['title' => 'Brush teeth', 'required' => true, 'slots' => [
                ['label' => 'Morning', 'due_by' => '08:00'],
                ['label' => 'Evening', 'available_from' => '18:00'],
            ]],
            ['title' => 'Walk the dog', 'value' => 50, 'currency' => 'money', 'slots' => [
                ['label' => 'Morning'], ['label' => 'Evening'],
            ]],
            ['title' => 'Rake the front yard', 'schedule_type' => 'one_off', 'due_date' => $sunday, 'value' => 500, 'currency' => 'money'],
            ['title' => 'Feed the fish', 'value' => 5, 'currency' => 'points', 'auto_approve' => true],
        ];
        foreach ($demo as $chore) {
            $chores->create($householdId, $chore + ['kid_ids' => $kidIds]);
        }
    }
}
