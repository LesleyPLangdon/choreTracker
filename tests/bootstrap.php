<?php
declare(strict_types=1);

// Test bootstrap: loads the app, creates a FRESH test database, and runs every migration.
require __DIR__ . '/../api/autoload.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

use App\Config;
use App\Migrator;

/** DB settings come from environment variables (see scripts/test.sh), never from config.php. */
function test_db_settings(): array
{
    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'choretracker_test',
        'user' => getenv('DB_USER') ?: 'choretracker',
        'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'choretracker',
    ];
}

function test_config(array $extra = []): Config
{
    return new Config(['db' => test_db_settings(), 'is_test_site' => true] + $extra);
}

(function (): void {
    $db = test_db_settings();
    $name = str_replace('`', '', $db['name']);
    $admin = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = App\Database::connect(test_config());
    (new Migrator($pdo, dirname(__DIR__) . '/db/migrations'))->run();
})();
