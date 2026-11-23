<?php
// Router for PHP's built-in server (which ignores .htaccess): sends /api/... to web/api/index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/') || $path === '/api') {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/../web/api/index.php';
    return true;
}
return false; // serve static files from web/
