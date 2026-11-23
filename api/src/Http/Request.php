<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string,string> $headers lower-cased header names */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly string $ip = '',
    ) {}

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip the folder this script lives in (e.g. /api), so routes are the same everywhere.
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php'));
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        // Some Apache/FastCGI setups expose the header only via a redirect variable.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException(400, 'Request body must be valid JSON.');
            }
            $body = $decoded;
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', '/' . trim($uri, '/'), $body, $headers, $_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** A trimmed string field ('' if missing or not a string). */
    public function str(string $key): string
    {
        $v = $this->body[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}
