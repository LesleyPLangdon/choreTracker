<?php
declare(strict_types=1);

namespace Tests;

use App\Clock;
use App\HouseholdTime;
use PHPUnit\Framework\TestCase;

final class HouseholdTimeTest extends TestCase
{
    private function today(string $tz, string $dayEnd, string $utcNow): string
    {
        return (new HouseholdTime($tz, $dayEnd, Clock::at($utcNow)))->today();
    }

    public function testUsesHouseholdTimeZoneNotUtc(): void
    {
        // 03:30 UTC on Dec 2 is still the evening of Dec 1 in New York (UTC-5).
        $this->assertSame('2026-12-01', $this->today('America/New_York', '00:00:00', '2026-12-02 03:30:00'));
        // ...but already Dec 2 in Tokyo (UTC+9).
        $this->assertSame('2026-12-02', $this->today('Asia/Tokyo', '00:00:00', '2026-12-02 03:30:00'));
    }

    public function testMidnightRollover(): void
    {
        $this->assertSame('2026-12-01', $this->today('America/New_York', '00:00:00', '2026-12-02 04:59:59')); // 23:59:59 local
        $this->assertSame('2026-12-02', $this->today('America/New_York', '00:00:00', '2026-12-02 05:00:00')); // 00:00:00 local
    }

    public function testEndOfDayTimeDelaysTheRollover(): void
    {
        // Day ends at 02:00: 01:30 local on Dec 2 still counts as Dec 1.
        $this->assertSame('2026-12-01', $this->today('America/New_York', '02:00:00', '2026-12-02 06:30:00'));
        // At exactly 02:00 local, Dec 2 begins.
        $this->assertSame('2026-12-02', $this->today('America/New_York', '02:00:00', '2026-12-02 07:00:00'));
        // Mid-afternoon is unaffected.
        $this->assertSame('2026-12-02', $this->today('America/New_York', '02:00:00', '2026-12-02 20:00:00'));
    }

    public function testSimulatingAnotherTimeWithTheClock(): void
    {
        $clock = Clock::at('2026-12-02 04:00:00');
        $time = new HouseholdTime('America/New_York', '00:00:00', $clock);
        $this->assertSame('2026-12-01', $time->today());
        $clock->advance('+2 hours');
        $this->assertSame('2026-12-02', $time->today());
        $clock->set('2026-07-04 12:00:00');
        $this->assertSame('2026-07-04', $time->today());
    }

    public function testDaylightSavingTransitions(): void
    {
        // US spring-forward: March 8, 2026. 05:00 UTC = 00:00 EST; 04:00 UTC next day = 00:00 EDT.
        $this->assertSame('2026-03-08', $this->today('America/New_York', '00:00:00', '2026-03-08 05:00:00'));
        $this->assertSame('2026-03-08', $this->today('America/New_York', '00:00:00', '2026-03-09 03:59:59'));
        $this->assertSame('2026-03-09', $this->today('America/New_York', '00:00:00', '2026-03-09 04:00:00'));
    }

    public function testDayBoundsInUtc(): void
    {
        $time = new HouseholdTime('America/New_York', '00:00:00', Clock::at('2026-12-01 15:00:00'));
        $this->assertSame(['2026-12-01 05:00:00', '2026-12-02 05:00:00'], $time->dayBoundsUtc('2026-12-01'));

        $late = new HouseholdTime('America/New_York', '02:00:00', Clock::at('2026-12-01 15:00:00'));
        $this->assertSame(['2026-12-01 07:00:00', '2026-12-02 07:00:00'], $late->dayBoundsUtc('2026-12-01'));

        // Bounds and dayFor agree: the start belongs to the day, the end belongs to the next.
        [$start, $end] = $late->dayBoundsUtc('2026-12-01');
        $this->assertSame('2026-12-01', $late->dayFor(new \DateTimeImmutable($start, new \DateTimeZone('UTC'))));
        $this->assertSame('2026-12-02', $late->dayFor(new \DateTimeImmutable($end, new \DateTimeZone('UTC'))));
    }

    public function testLocalUtcConversion(): void
    {
        $time = new HouseholdTime('America/New_York', '00:00:00', Clock::at('2026-12-01 15:00:00'));
        $this->assertSame('2026-12-04 22:00:00', $time->localToUtc('2026-12-04 17:00:00'));
        $this->assertSame('2026-12-04 17:00:00', $time->utcToLocal('2026-12-04 22:00:00'));
    }
}
