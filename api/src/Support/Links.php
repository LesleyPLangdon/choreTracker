<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/** Goal links: plain text, http(s) only. We never fetch, preview, or embed anything from them. */
final class Links
{
    public const MAX_LENGTH = 500;

    /** Clean a link from a form: '' / null → null; anything that isn't a plain http(s) URL is refused. */
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException(422, 'That link doesn\'t look right.');
        }
        $url = trim($value);
        if ($url === '') {
            return null;
        }
        if (strlen($url) > self::MAX_LENGTH) {
            throw new ApiException(422, 'That link is too long (500 characters at most).');
        }
        if (preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            throw new ApiException(422, 'That link can\'t have spaces in it.');
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ApiException(422, 'Links must start with http:// or https://');
        }
        return $url;
    }

    /** The site name to show for a link: 'https://www.dickssportinggoods.com/p/bat' → 'dickssportinggoods.com'. */
    public static function host(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        return $host === '' ? null : preg_replace('/^www\./', '', $host);
    }
}
