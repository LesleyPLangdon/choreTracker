<?php
declare(strict_types=1);

// Browser fallback for running migrations when cPanel Terminal isn't handy.
// OFF unless config.php has 'web_migrate' => ['enabled' => true, 'key' => '<long random key>'].
// Visit: /migrate.php?key=YOUR_KEY   (turn it off again when you're done)

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$appDir = is_file(__DIR__ . '/api/app-dir.php') ? (require __DIR__ . '/api/app-dir.php') : dirname(__DIR__);
require $appDir . '/api/bootstrap.php';

use App\Config;
use App\Database;
use App\Migrator;

try {
    $config = Config::load($appDir . '/config.php');
    $key = (string) $config->get('web_migrate.key', '');
    if ($config->get('web_migrate.enabled') !== true || strlen($key) < 20) {
        http_response_code(404);
        exit("Not found.\n");
    }
    if (!hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Wrong key.\n");
    }
    $applied = (new Migrator(Database::connect($config), $appDir . '/db/migrations'))->run();
    echo $applied ? "Applied:\n  " . implode("\n  ", $applied) . "\n" : "Database is already up to date.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('migrate.php: ' . $e);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
