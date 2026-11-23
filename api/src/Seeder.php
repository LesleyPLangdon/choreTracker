<?php
declare(strict_types=1);

namespace App;

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
        $kid = $this->pdo->prepare(
            'INSERT INTO kids (household_id, nickname, avatar, pin_hash, link_token_hash, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        foreach ([['Alex', '🦊'], ['Sam', '🐼']] as [$nickname, $avatar]) {
            $token = Tokens::generate('kl_');
            $kid->execute([$householdId, $nickname, $avatar, password_hash(self::KID_PIN, PASSWORD_DEFAULT), Tokens::hash($token), $now, $now]);
            $links[$nickname] = $token;
        }
        return ['created' => true, 'kid_links' => $links];
    }
}
