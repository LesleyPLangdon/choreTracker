<?php
declare(strict_types=1);

namespace App\Http;

/** Thrown anywhere in a handler to produce a JSON error response. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
