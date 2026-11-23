<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

final class Validate
{
    public const AVATARS = ['🦊', '🐼', '🐯', '🦁', '🐸', '🐵', '🐧', '🐙', '🦄', '🐲', '🚀', '⚽'];

    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'Please enter a valid email address.');
        }
        return $email;
    }

    public static function password(string $password): string
    {
        if (strlen($password) < 10) {
            throw new ApiException(422, 'Password must be at least 10 characters.');
        }
        if (strlen($password) > 200) {
            throw new ApiException(422, 'Password is too long.');
        }
        return $password;
    }

    public static function name(string $value, string $label, int $max): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new ApiException(422, "$label is required (up to $max characters).");
        }
        return $value;
    }

    public static function timezone(string $tz): string
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new ApiException(422, 'Please choose a valid time zone.');
        }
        return $tz;
    }

    /** Accepts 'H:i' or 'H:i:s'; returns 'H:i:s'. */
    public static function time(string $value, string $label = 'Time'): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            throw new ApiException(422, "$label must look like 02:30.");
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    public static function pin(string $pin): string
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new ApiException(422, 'PIN must be 4 to 6 digits.');
        }
        return $pin;
    }

    public static function avatar(string $avatar): string
    {
        if (!in_array($avatar, self::AVATARS, true)) {
            throw new ApiException(422, 'Please choose one of the available avatars.');
        }
        return $avatar;
    }
}
