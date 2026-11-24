<?php
declare(strict_types=1);

namespace Tests;

/**
 * Challenges: once, daily, and weekly windows; counting (reported in the window AND approved); periods made on demand.
 * New York is UTC-5 in December: Friday 5:00 PM = 22:00 UTC; 6:30 AM = 11:30 UTC.
 */
final class ChallengesTest extends MoneyTestCase
{
    private function group(string $title): string
    {
        $stmt = $this->pdo->prepare('SELECT group_id FROM chores WHERE title = ? ORDER BY id LIMIT 1');
        $stmt->execute([$title]);
        return (string) $stmt->fetchColumn();
    }

    private function walkTheDog(): void
    {
        $this->chore(['title' => 'Walk the dog', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
    }

    /** "Walk the dog N times by Friday 5 PM": weekly, Friday 5:00 PM to Friday 5:00 PM. */
    private function weekly(int $target = 4): int
    {
        return $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => 'Open gym', 'target_count' => $target, 'chore_group_ids' => [$this->group('Walk the dog')],
            'repeat_type' => 'weekly', 'start_weekday' => 5, 'start_time' => '17:00', 'end_weekday' => 5, 'end_time' => '17:00',
        ]), 201)['challenge_id'];
    }

    private function periods(): array
    {
        return $this->pdo->query('SELECT status, period_start, period_end FROM challenge_periods ORDER BY period_start')->fetchAll();
    }

    private function kidChallenge(int $index = 0): array
    {
        return $this->ok($this->kidCall('GET', '/kid/challenges'))['challenges'][$index];
    }

    /** Do (report) every list row for a chore title; returns the instance ids. */
    private function doAll(string $title): array
    {
        $ids = [];
        foreach ($this->kidList($this->kid)['chores'] as $c) {
            if ($c['title'] === $title && $c['can_report']) {
                $this->assertSame(200, $this->done($c['id'])[0]);
                $ids[] = $c['id'];
            }
        }
        return $ids;
    }

    private function periodCount(): int
    {
        $this->ok($this->parentCall('GET', '/challenges')); // any request refreshes periods
        return (int) $this->pdo->query('SELECT COUNT(*) FROM challenge_periods')->fetchColumn();
    }

    // ---- weekly: counting -----------------------------------------------------------------------------

    public function testWeeklyWindowRunsFridayToFridayAndCountsOnlyReportedAndApproved(): void
    {
        $this->walkTheDog();
        $this->weekly(4);
        $p = $this->periods();
        $this->assertCount(1, $p);
        $this->assertSame(['in_progress', '2026-11-27 22:00:00', '2026-12-04 22:00:00'], array_values($p[0]), 'Fri 5 PM to Fri 5 PM, stored in UTC');

        $this->clock->set(self::DEC1);
        $day1 = $this->doAll('Walk the dog');
        $this->assertCount(2, $day1);
        $c = $this->kidChallenge();
        $this->assertSame(0, $c['period']['count'], 'reported but not approved does not count');
        $this->approve($day1[0]);
        $this->assertSame(1, $this->kidChallenge()['period']['count']);

        $this->clock->set(self::DEC2);
        $day2 = $this->doAll('Walk the dog');
        $this->approve($day1[1]);
        $this->approve($day2[0]);
        $c = $this->kidChallenge();
        $this->assertSame([3, 4, 'in_progress'], [$c['period']['count'], $c['target_count'], $c['period']['status']]);
        $this->assertSame('Open gym', $c['reward_name']);
        $this->assertSame(['Walk the dog'], $c['chores']);
        $this->assertSame('2026-12-04 17:00:00', $c['period']['end_local'], 'the deadline, in household time');

        $this->approve($day2[1]);
        $c = $this->kidChallenge();
        $this->assertSame(['earned', 4], [$c['period']['status'], $c['period']['count']]);
        $items = $this->approvals();
        $this->assertSame(['challenge_earned'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);
        $this->assertSame('Open gym', $items['items'][0]['reward_name']);
        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM ledger_entries WHERE entry_type NOT IN ('earning')"), 'a challenge reward costs nothing: no ledger entries');
    }

    public function testAnApprovalAfterTheWindowClosesStillCounts(): void
    {
        $this->walkTheDog();
        $this->weekly(4);
        $this->clock->set('2026-12-03 21:00:00');
        $a = $this->doAll('Walk the dog');
        $this->clock->set('2026-12-04 21:59:59'); // one second before Friday 5:00 PM
        $b = $this->doAll('Walk the dog');
        $this->assertCount(2, $a);
        $this->assertCount(2, $b);

        $this->clock->set('2026-12-05 15:00:00'); // Saturday: the window has closed and nothing is approved yet
        $this->assertSame('in_progress', $this->kidChallenge()['period']['status'], 'chores waiting on a parent keep it open');
        foreach (array_merge($a, $b) as $id) {
            $this->approve($id);
        }
        $c = $this->kidChallenge();
        $this->assertSame('in_progress', $c['period']['status'], 'the next window is open now (0 so far)');
        $this->assertCount(1, $c['earned'], 'and last window\'s reward is waiting: "Earned! Ask a parent."');
        $this->assertSame([4, 'earned'], [$c['earned'][0]['count'], $c['earned'][0]['status']]);
        $this->assertSame(['earned', 'in_progress'], array_column($this->periods(), 'status'));
    }

    public function testChoresReportedOutsideTheWindowDoNotCount(): void
    {
        $this->walkTheDog();
        $this->weekly(2);
        $this->clock->set('2026-12-04 22:00:00'); // exactly Friday 5:00 PM: the end is exclusive, so this is the NEXT window
        $ids = $this->doAll('Walk the dog');
        foreach ($ids as $id) {
            $this->approve($id);
        }
        $this->periodCount();
        $p = $this->periods();
        $this->assertSame(['missed', 'earned'], array_column($p, 'status'), 'old window: nothing reported inside it; new window: both chores');
        $this->assertSame('2026-12-04 22:00:00', $p[1]['period_start']);
    }

    public function testStartOfTheWindowIsInclusive(): void
    {
        $this->walkTheDog();
        $this->weekly(1);
        $this->clock->set('2026-12-04 22:00:00');
        $id = $this->doAll('Walk the dog')[0];
        $this->approve($id);
        $this->periodCount();
        $this->assertSame('earned', $this->periods()[1]['status'], 'reported exactly at the start counts in the new window');
    }

    public function testMissedOnlyOnceEveryReportedChoreIsDecided(): void
    {
        $this->walkTheDog();
        $this->weekly(4);
        $this->clock->set(self::DEC3);
        $ids = $this->doAll('Walk the dog'); // two reported, none approved
        $this->clock->set('2026-12-05 15:00:00');
        $this->periodCount();
        $this->assertSame('in_progress', $this->periods()[0]['status'], 'two are still waiting for a parent');
        $this->approve($ids[0]);
        $this->periodCount();
        $this->assertSame('in_progress', $this->periods()[0]['status'], 'one is still undecided');
        $this->sendBack($ids[1]); // decided (sent back)
        $this->periodCount();
        $this->assertSame('missed', $this->periods()[0]['status'], 'all decided and below the target');
        $this->assertSame(0, $this->approvals()['count'], 'a missed challenge needs no action');
    }

    public function testCompletionsOnAForfeitedDayStillCount(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->walkTheDog();
        $this->weekly(2);
        $this->clock->set(self::DEC1);
        foreach ($this->doAll('Walk the dog') as $id) {
            $this->approve($id);
        }
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'), 'the required bed was never done');
        $this->assertSame('earned', $this->kidChallenge()['period']['status'], 'independent of forfeits');
    }

    public function testSlotsLimitItToTwoADayButNotMore(): void
    {
        $this->walkTheDog();
        $this->weekly(7);
        $this->clock->set(self::DEC1);
        $ids = $this->doAll('Walk the dog');
        foreach ($ids as $id) {
            $this->approve($id);
        }
        $this->assertSame(2, $this->kidChallenge()['period']['count']);
        $this->assertSame([], $this->doAll('Walk the dog'), 'both slots are done for today');
        $this->assertSame(409, $this->done($ids[0])[0]);
        $this->assertSame(2, $this->kidChallenge()['period']['count']);
    }

    // ---- periods on demand ------------------------------------------------------------------------------------

    public function testPeriodsAreCreatedOnDemandAndIdempotently(): void
    {
        $this->walkTheDog();
        $this->weekly(4);
        $this->assertSame(1, $this->periodCount());
        for ($i = 0; $i < 4; $i++) {
            $this->periodCount();
            $this->kidChallenge();
        }
        $this->assertSame(1, $this->periodCount(), 'repeating requests make no extra periods');

        $this->clock->set('2026-12-21 15:00:00'); // three weeks pass with nobody opening the app
        $this->assertSame(4, $this->periodCount(), 'Nov 27, Dec 4, Dec 11, Dec 18 windows');
        $statuses = array_column($this->periods(), 'status');
        $this->assertSame(['missed', 'missed', 'missed', 'in_progress'], $statuses);
        $this->assertSame(4, $this->periodCount());
        $starts = array_column($this->periods(), 'period_start');
        $this->assertSame(['2026-11-27 22:00:00', '2026-12-04 22:00:00', '2026-12-11 22:00:00', '2026-12-18 22:00:00'], $starts);
    }

    public function testAChallengeDoesNotCountWindowsThatEndedBeforeItWasMade(): void
    {
        $this->walkTheDog();
        $this->clock->set('2026-12-07 15:00:00'); // Monday
        $this->weekly(4);
        $this->assertSame(['2026-12-04 22:00:00'], array_column($this->periods(), 'period_start'), 'the window already in progress; earlier weeks are not made');
    }

    // ---- daily, weekdays only -------------------------------------------------------------------------------

    private function readyForSchool(): int
    {
        foreach (['Get dressed', 'Brush teeth', 'Pack backpack'] as $title) {
            $this->chore(['title' => $title, 'days' => [1, 2, 3, 4, 5]]);
        }
        return $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => '30 minutes of TV', 'target_count' => 3,
            'chore_group_ids' => [$this->group('Get dressed'), $this->group('Brush teeth'), $this->group('Pack backpack')],
            'repeat_type' => 'daily', 'days' => [1, 2, 3, 4, 5], 'start_time' => '06:00', 'end_time' => '07:30',
        ]), 201)['challenge_id'];
    }

    public function testDailyWeekdayWindowCountsOnlyChoresReportedBetween6And730(): void
    {
        $this->readyForSchool();
        $this->clock->set('2026-12-01 11:30:00'); // Tuesday 6:30 AM
        $ids = [];
        foreach (['Get dressed', 'Brush teeth', 'Pack backpack'] as $title) {
            $ids[] = $this->doAll($title)[0];
        }
        $c = $this->kidChallenge();
        $this->assertSame([0, 'in_progress'], [$c['period']['count'], $c['period']['status']]);
        $this->assertSame('2026-12-01 06:00:00', $c['period']['start_local']);
        $this->assertSame('2026-12-01 07:30:00', $c['period']['end_local']);
        $this->approve($ids[0]);
        $this->approve($ids[1]);
        $this->assertSame(2, $this->kidChallenge()['period']['count']);
        $this->approve($ids[2]);
        $this->assertSame('earned', $this->kidChallenge()['period']['status'], '3 of 3 before 7:30');

        // Wednesday: done at 8:00 AM, after the window: doesn't count.
        $this->clock->set('2026-12-02 13:00:00');
        foreach (['Get dressed', 'Brush teeth', 'Pack backpack'] as $title) {
            $this->approve($this->doAll($title)[0]);
        }
        $statuses = array_column($this->periods(), 'status', 'period_start');
        $this->assertSame('missed', $statuses['2026-12-02 11:00:00']);
        $this->assertSame('earned', $statuses['2026-12-01 11:00:00']);
    }

    public function testDailyWindowsSkipTheWeekend(): void
    {
        $this->readyForSchool();
        $this->clock->set('2026-12-05 15:00:00'); // Saturday
        $this->periodCount();
        $starts = array_column($this->periods(), 'period_start');
        $this->assertSame(['2026-12-01 11:00:00', '2026-12-02 11:00:00', '2026-12-03 11:00:00', '2026-12-04 11:00:00'], $starts, 'Tue-Fri only; none for Mon Nov 30 (already over when it was made) or the weekend');
        $c = $this->kidChallenge();
        $this->assertNull($c['period'] === null ? null : ($c['period']['open'] ? 'open' : null), 'nothing is open on a Saturday');
        $this->assertSame('2026-12-07 06:00:00', $c['next_start_local'], 'the next window opens Monday at 6:00');
    }

    public function testDailyWindowCrossingMidnightIsRefused(): void
    {
        $this->chore(['title' => 'X']);
        $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => 'R', 'target_count' => 1, 'chore_group_ids' => [$this->group('X')],
            'repeat_type' => 'daily', 'days' => [1], 'start_time' => '22:00', 'end_time' => '06:00',
        ]), 422);
    }

    // ---- one time ---------------------------------------------------------------------------------------------

    public function testOneTimeWindow(): void
    {
        $this->walkTheDog();
        $id = $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => 'Pizza night', 'target_count' => 2, 'chore_group_ids' => [$this->group('Walk the dog')],
            'repeat_type' => 'once', 'once_start' => '2026-12-02 09:00', 'once_end' => '2026-12-02T17:00',
        ]), 201)['challenge_id'];
        $this->assertSame([], $this->periods(), 'the window has not started yet');
        $c = $this->kidChallenge();
        $this->assertNull($c['period']);
        $this->assertSame('2026-12-02 09:00:00', $c['next_start_local']);

        $this->clock->set('2026-12-02 13:00:00'); // 8 AM: before the window
        $early = $this->doAll('Walk the dog');
        $this->assertCount(2, $early);
        foreach ($early as $x) {
            $this->approve($x);
        }
        $this->assertSame([], $this->periods(), 'still 8 AM: the window has not opened');
        $this->clock->set('2026-12-02 15:00:00'); // 10 AM: the window is open now
        $c = $this->kidChallenge();
        $this->assertSame([0, 'in_progress'], [$c['period']['count'], $c['period']['status']], 'what was reported before the window opened does not count');
        $this->assertSame('2026-12-02 14:00:00', $this->periods()[0]['period_start']);
    }

    public function testOneTimeWindowEarnedAndMissed(): void
    {
        $this->walkTheDog();
        $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => 'Pizza night', 'target_count' => 2, 'chore_group_ids' => [$this->group('Walk the dog')],
            'repeat_type' => 'once', 'once_start' => '2026-12-02 09:00', 'once_end' => '2026-12-02 17:00',
        ]), 201);
        $this->clock->set('2026-12-02 16:00:00'); // 11 AM, inside
        foreach ($this->doAll('Walk the dog') as $x) {
            $this->approve($x);
        }
        $this->assertSame('earned', $this->kidChallenge()['period']['status']);
        $this->assertCount(1, $this->periods(), 'a one-time challenge has exactly one period');
    }

    // ---- overrides and delivery -------------------------------------------------------------------------------

    public function testParentCanMarkAPeriodEarnedOrMissedAndDelivered(): void
    {
        $this->walkTheDog();
        $this->weekly(7);
        $this->clock->set(self::DEC1);
        $this->periodCount();
        $period = (int) $this->pdo->query('SELECT id FROM challenge_periods')->fetchColumn();

        // A drive day changed the schedule: the parent marks it earned.
        $e = $this->ok($this->parentCall('POST', "/challenge-periods/$period/earned"))['period'];
        $this->assertSame('earned', $e['status']);
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/earned"), 409);
        $this->assertSame(1, $this->approvals()['count']);
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/delivered"));
        $this->assertSame(0, $this->approvals()['count']);
        $this->assertSame('delivered', $this->kidChallenge()['period']['status'] ?? 'delivered');
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/delivered"), 409);
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/missed"), 409);

        // And the other way round (a fresh period): earned -> missed.
        $this->clock->set('2026-12-07 15:00:00');
        $this->periodCount();
        $second = (int) $this->pdo->query("SELECT id FROM challenge_periods WHERE status = 'in_progress' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->ok($this->parentCall('POST', "/challenge-periods/$second/earned"));
        $this->ok($this->parentCall('POST', "/challenge-periods/$second/missed"));
        $this->assertSame('missed', $this->pdo->query("SELECT status FROM challenge_periods WHERE id = $second")->fetchColumn());
        $this->assertSame(0, $this->approvals()['count']);
        $this->ok($this->parentCall('POST', "/challenge-periods/$second/delivered"), 409);
        $this->ok($this->parentCall('POST', "/challenge-periods/$second/bogus"), 404);
    }

    public function testOverridesAreNotUndoneByLaterRefreshes(): void
    {
        $this->walkTheDog();
        $this->weekly(7);
        $this->clock->set(self::DEC1);
        $this->periodCount();
        $period = (int) $this->pdo->query('SELECT id FROM challenge_periods')->fetchColumn();
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/missed"));
        $this->clock->set('2026-12-03 15:00:00');
        foreach ($this->doAll('Walk the dog') as $x) {
            $this->approve($x);
        }
        $this->periodCount();
        $this->assertSame('missed', $this->pdo->query("SELECT status FROM challenge_periods WHERE id = $period")->fetchColumn());
    }

    // ---- creating, removing, who can do what ---------------------------------------------------------------

    public function testChallengeValidation(): void
    {
        $this->walkTheDog();
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $this->createChore($this->parent, [$sam], ['title' => 'Sams chore']);
        $good = ['kid_id' => $this->kidId, 'reward_name' => 'R', 'target_count' => 2, 'chore_group_ids' => [$this->group('Walk the dog')],
            'repeat_type' => 'weekly', 'start_weekday' => 5, 'start_time' => '17:00', 'end_weekday' => 5, 'end_time' => '17:00'];
        foreach ([
            ['kid_id' => 9999], ['reward_name' => ''], ['target_count' => 0], ['target_count' => 1.5], ['chore_group_ids' => []],
            ['chore_group_ids' => ['nonsense']], ['chore_group_ids' => [$this->group('Sams chore')]], ['repeat_type' => 'monthly'],
            ['start_weekday' => 8], ['start_time' => '25:00'],
            ['repeat_type' => 'once', 'once_start' => 'soon', 'once_end' => '2026-12-02 17:00'],
            ['repeat_type' => 'once', 'once_start' => '2026-12-02 17:00', 'once_end' => '2026-12-02 09:00'],
            ['repeat_type' => 'daily', 'days' => [], 'start_time' => '06:00', 'end_time' => '07:00'],
            ['repeat_type' => 'daily', 'days' => [9], 'start_time' => '06:00', 'end_time' => '07:00'],
        ] as $bad) {
            $this->ok($this->parentCall('POST', '/challenges', $bad + $good), 422);
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM challenges'));
        $this->ok($this->parentCall('POST', '/challenges', $good), 201);
    }

    public function testRemoveAChallenge(): void
    {
        $this->walkTheDog();
        $id = $this->weekly();
        $this->ok($this->parentCall('DELETE', "/challenges/$id"));
        $this->assertSame([], $this->ok($this->kidCall('GET', '/kid/challenges'))['challenges']);
        $this->assertSame([], $this->ok($this->parentCall('GET', '/challenges'))['challenges']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM challenges'), 'kept, not deleted');
    }

    public function testParentListShowsPeriodsAndKidsOnlySeeTheirOwn(): void
    {
        $this->walkTheDog();
        $this->weekly();
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $samToken = $this->kidToken($samLink);
        $this->assertSame([], $this->ok($this->call('GET', '/kid/challenges', [], $samToken))['challenges']);
        $list = $this->ok($this->parentCall('GET', '/challenges'))['challenges'];
        $this->assertCount(1, $list);
        $this->assertSame('Alex', $list[0]['kid']['nickname']);
        $this->assertSame('weekly', $list[0]['repeat_type']);
        $this->assertCount(1, $list[0]['periods']);
    }

    public function testPermissions(): void
    {
        $this->walkTheDog();
        $id = $this->weekly();
        $this->clock->set(self::DEC1);
        $this->periodCount();
        $period = (int) $this->pdo->query('SELECT id FROM challenge_periods')->fetchColumn();
        [$other] = $this->registerParent('other@example.com');
        foreach ([['DELETE', "/challenges/$id"], ['POST', "/challenge-periods/$period/earned"], ['POST', "/challenge-periods/$period/missed"], ['POST', "/challenge-periods/$period/delivered"]] as [$m, $p]) {
            $this->ok($this->call($m, $p, [], $other), 404);
        }
        foreach ([['GET', '/challenges'], ['POST', '/challenges'], ['DELETE', "/challenges/$id"], ['POST', "/challenge-periods/$period/earned"]] as [$m, $p]) {
            $this->ok($this->kidCall($m, $p), 401);
        }
        $this->assertSame('in_progress', $this->pdo->query("SELECT status FROM challenge_periods WHERE id = $period")->fetchColumn());
    }
}
