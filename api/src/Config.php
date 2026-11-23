<?php
declare(strict_types=1);

namespace App;

/** Reads config.php (git-ignored). Path can be overridden with the CHORE_CONFIG env var. */
final class Config
{
    public function __construct(private array $values) {}

    public static function defaultPath(): string
    {
        return getenv('CHORE_CONFIG') ?: dirname(__DIR__, 2) . '/config.php';
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();
        if (!is_file($path)) {
            throw new \RuntimeException("Config file not found: $path (copy config.example.php to config.php)");
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException("Config file must return an array: $path");
        }
        return new self($values);
    }

    /** Dot-notation lookup, e.g. get('db.host'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function isTestSite(): bool
    {
        return $this->get('is_test_site', false) === true;
    }
}
