<?php
declare(strict_types=1);

// Usage: php scripts/migrate.php
// Applies any new files in db/migrations. Safe to run again.
require __DIR__ . '/../api/bootstrap.php';

use App\Config;
use App\Database;
use App\Migrator;

$config = Config::load();
$applied = (new Migrator(Database::connect($config), dirname(__DIR__) . '/db/migrations'))->run();
echo $applied ? "Applied:\n  " . implode("\n  ", $applied) . "\n" : "Database is already up to date.\n";
