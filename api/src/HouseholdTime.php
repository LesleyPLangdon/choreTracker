<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Household-local time rules. All "what day is it for this family" questions go through here.
 *
 * End-of-day: a household's `day_end_time` (default 00:00) is when its day rolls over. A day
 * labelled D runs from D at day_end_time until D+1 at day_end_time. With the default 00:00 this is
 * the ordinary calendar day; with 02:00, 1:30 AM still counts as the previous day.
 */
final class HouseholdTime
{
    private DateTimeZone $tz;
    private int $dayEndMinutes;

    public function __construct(string $timezone, string $dayEndTime, private Clock $clock)
    {
        $this->tz = new DateTimeZone($timezone);
        $parts = explode(':', $dayEndTime);
        $this->dayEndMinutes = ((int) $parts[0]) * 60 + (int) ($parts[1] ?? 0);
    }

    public static function forHousehold(array $household, Clock $clock): self
    {
        return new self($household['timezone'], $household['day_end_time'], $clock);
    }

    /** The household's current day label, 'Y-m-d'. */
    public function today(): string
    {
        return $this->dayFor($this->clock->now());
    }

    /** The day label ('Y-m-d') that a UTC moment falls into. */
    public function dayFor(DateTimeImmutable $utc): string
    {
        $local = $utc->setTimezone($this->tz);
        // Shift the wall-clock back by the end-of-day offset, ignoring DST, then take the date.
        $wall = new DateTimeImmutable($local->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        return $wall->modify('-' . $this->dayEndMinutes . ' minutes')->format('Y-m-d');
    }

    /** UTC [start, end) of a day label, as 'Y-m-d H:i:s' strings. End is exclusive. */
    public function dayBoundsUtc(string $day): array
    {
        $start = $this->localToUtc($day . ' ' . $this->dayEndClock());
        $next = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $end = $this->localToUtc($next . ' ' . $this->dayEndClock());
        return [$start, $end];
    }

    /** Convert a household-local 'Y-m-d H:i:s' wall-clock time to a UTC 'Y-m-d H:i:s' string. */
    public function localToUtc(string $local): string
    {
        return (new DateTimeImmutable($local, $this->tz))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Convert a UTC 'Y-m-d H:i:s' string to household-local 'Y-m-d H:i:s'. */
    public function utcToLocal(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone($this->tz)->format('Y-m-d H:i:s');
    }

    private function dayEndClock(): string
    {
        return sprintf('%02d:%02d:00', intdiv($this->dayEndMinutes, 60), $this->dayEndMinutes % 60);
    }
}
