<?php
declare(strict_types=1);

// API entry point. Everything under /api/ is routed through here (see .htaccess).
//
// Where is the app code? In development it sits next to web/. On the server the deploy script
// puts it outside the public web folder and writes app-dir.php to point at it.
$appDir = is_file(__DIR__ . '/app-dir.php') ? (require __DIR__ . '/app-dir.php') : dirname(__DIR__, 2);

require $appDir . '/api/bootstrap.php';

use App\App;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

try {
    $config = Config::load($appDir . '/config.php');
    $app = App::fromConfig($config);
    $response = $app->handle(Request::fromGlobals());
} catch (ApiException $e) {
    $response = new Response($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Chore Tracker startup error: ' . $e->getMessage());
    $response = new Response(500, ['error' => 'The app is not set up correctly yet. Check config.php and the database.']);
}
$response->send();
