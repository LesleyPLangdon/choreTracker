<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The ONE source of "now" for the whole app. Never call time(), date(), new DateTime('now'),
 * or SQL NOW() elsewhere — ask the Clock. Tests (and the test site's `simulated_now` setting)
 * can then pretend it is any moment, to try end-of-day rules.
 */
class Clock
{
    private ?DateTimeImmutable $fixed;

    public function __construct(?DateTimeImmutable $fixed = null)
    {
        $this->fixed = $fixed?->setTimezone(new DateTimeZone('UTC'));
    }

    /** Fixed clock from a UTC string like '2026-12-01 03:30:00'. */
    public static function at(string $utc): self
    {
        return new self(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** UTC "Y-m-d H:i:s" for storing in the database. */
    public function nowSql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /** Freeze the clock at a UTC moment (tests / simulation). Pass null to follow real time. */
    public function set(?string $utc): void
    {
        $this->fixed = $utc === null ? null : new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /** Move a frozen clock forward, e.g. advance('+16 minutes'). */
    public function advance(string $modifier): void
    {
        $this->fixed = $this->now()->modify($modifier);
    }

    /** UTC SQL string for "now plus a modifier", e.g. in('+15 minutes'). */
    public function in(string $modifier): string
    {
        return $this->now()->modify($modifier)->format('Y-m-d H:i:s');
    }
}
