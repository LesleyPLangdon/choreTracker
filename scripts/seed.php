<?php
declare(strict_types=1);

// Usage: php scripts/seed.php        (TEST SITE ONLY)
// Creates the demo household if it isn't there yet.
require __DIR__ . '/../api/bootstrap.php';

use App\Clock;
use App\Config;
use App\Database;
use App\Seeder;

try {
    $config = Config::load();
    $result = (new Seeder(Database::connect($config), $config, new Clock()))->seed();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (!$result['created']) {
    echo "Demo household already exists. (Use scripts/reset.php to start over.)\n";
    exit(0);
}
$base = rtrim((string) $config->get('app_url', 'https://YOUR-TEST-SITE'), '/');
echo "Demo household created.\n";
echo "Parents: test@example.com and parent2@example.com, password Correct-Horse-42\n";
echo "Kids (PIN 1234):\n";
foreach ($result['kid_links'] as $nickname => $token) {
    echo "  $nickname: $base/#/k/$token\n";
}
