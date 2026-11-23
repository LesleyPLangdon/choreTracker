<?php
declare(strict_types=1);

// Usage: php scripts/reset.php       (TEST SITE ONLY)
// Deletes ALL app data and re-creates the demo household.
require __DIR__ . '/../api/bootstrap.php';

use App\Clock;
use App\Config;
use App\Database;
use App\Seeder;

try {
    $config = Config::load();
    $seeder = new Seeder(Database::connect($config), $config, new Clock());
    $seeder->reset();
    $result = $seeder->seed();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$base = rtrim((string) $config->get('app_url', 'https://YOUR-TEST-SITE'), '/');
echo "All data deleted and demo household re-created.\n";
echo "Parents: test@example.com and parent2@example.com, password Correct-Horse-42\n";
echo "Kids (PIN 1234):\n";
foreach ($result['kid_links'] as $nickname => $token) {
    echo "  $nickname: $base/#/k/$token\n";
}
