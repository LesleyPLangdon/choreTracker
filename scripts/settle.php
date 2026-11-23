<?php
declare(strict_types=1);

// Usage: php scripts/settle.php
// Settles every household's ended days (the same work the app does on demand whenever anyone opens it).
// Optional: add this as a cPanel cron job (e.g. every hour) if you want days settled even when nobody opens the app.
require __DIR__ . '/../api/bootstrap.php';

use App\App;
use App\Config;

try {
    $app = App::fromConfig(Config::load());
    $results = $app->settler->settleAll();
} catch (Throwable $e) {
    fwrite(STDERR, 'Settle failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$days = array_sum($results);
echo 'Settled ' . $days . ' day' . ($days === 1 ? '' : 's') . ' across ' . count($results) . ' household' . (count($results) === 1 ? '' : 's') . ".\n";
