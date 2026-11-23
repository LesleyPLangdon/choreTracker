<?php
declare(strict_types=1);

namespace App;

use PDO;

/** Runs db/migrations/NNN_name.sql files in order, once each. Safe to run repeatedly. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir) {}

    /** @return string[] names of the migrations applied by this call */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               name VARCHAR(190) NOT NULL PRIMARY KEY,
               applied_at DATETIME NOT NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach ($this->splitStatements((string) file_get_contents($file)) as $sql) {
                $this->pdo->exec($sql); // DDL auto-commits in MySQL, so no transaction here.
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)')
                ->execute([$name, gmdate('Y-m-d H:i:s')]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Split on semicolons at end of line; strips "--" comment lines. */
    private function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) as $stmt) {
            if (trim($stmt) !== '') {
                $out[] = trim($stmt);
            }
        }
        return $out;
    }

    /** Test/dev helper: drop every table in the current database. */
    public static function dropAll(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
