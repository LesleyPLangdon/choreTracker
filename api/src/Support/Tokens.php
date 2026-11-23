<?php
declare(strict_types=1);

namespace App\Support;

/** Random secrets (bearer tokens, kid links, invites). Only the sha256 hash is ever stored. */
final class Tokens
{
    public static function generate(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(32)); // 256 bits
    }

    /** These are long random values, so a fast hash is appropriate (unlike passwords/PINs). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
