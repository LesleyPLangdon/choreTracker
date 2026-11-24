<?php
declare(strict_types=1);

namespace Tests;

/** End-of-day settlement: days nobody opened, idempotency, expire vs carry over, time zones. */
final class SettlementTest extends RulesTestCase
{
    // ---- days nobody opened -----------------------------------------------------------------------------

    public function testDaysNobodyOpenedAreCreatedAndSettledInARow(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->chore(['title' => 'Dishes', 'days' => [1, 3, 5]]); // Mon, Wed, Fri
        // Nobody opens the app Dec 1 - Dec 4; the first request is on Dec 5 (Saturday).
        $this->clock->set(self::DEC5);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM chore_instances WHERE local_date = '2026-12-02'")->fetchColumn(), 'nothing exists yet');
        $this->kidList($this->kid);

        $bed = $this->instanceStatuses('Make bed');
        $this->assertSame(['2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05'], array_keys($bed));
        // Nov 30 was the creation day (not required yet), Dec 1-4 required and never done: forfeited.
        $this->assertSame('unlocked', $this->dayStatus('2026-11-30'));
        foreach (['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04'] as $d) {
            $this->assertSame('forfeited', $this->dayStatus($d), $d);
        }
        $this->assertSame('missed', $bed['2026-12-03']);
        // Dishes: only Mon/Wed/Fri that existed: Nov 30 (Mon), Dec 2 (Wed), Dec 4 (Fri).
        $this->assertSame(['2026-11-30', '2026-12-02', '2026-12-04'], array_keys($this->instanceStatuses('Dishes')));
        $this->assertSame('2026-12-04', $this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
    }

    public function testNothingIsCreatedBeforeAChoreOrTheHouseholdExisted(): void
    {
        $this->clock->set('2026-12-03 15:00:00');
        $this->chore(['title' => 'New chore', 'required' => true]); // made on Thursday Dec 3
        $creator = (new \App\Services($this->pdo, $this->clock))->creator;
        foreach (['2026-11-20', '2026-12-01', '2026-12-02'] as $day) {
            $creator->ensureDay($this->householdId, $this->kidId, $day);
        }
        $this->assertSame(['2026-12-03'], array_keys($this->instanceStatuses('New chore')), 'only its creation day, nothing before it existed');
        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM chore_instances WHERE local_date < '2026-12-03'"));

        // Settling starts at the household's first day and stops before today.
        $this->clock->set('2026-12-06 15:00:00');
        $this->kidList($this->kid);
        $this->assertSame(['2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06'], array_keys($this->instanceStatuses('New chore')));
        $this->assertNull($this->dayStatus('2026-11-29'), 'no days before the household existed');
    }

    public function testASlotAddedLaterIsNotCreatedForEarlierDays(): void
    {
        $id = $this->chore(['title' => 'Teeth', 'required' => true, 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $slots = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0]['slots'];
        $this->clock->set(self::DEC3);
        $this->kidList($this->kid); // settles Nov 30 - Dec 2 with two slots
        $this->call('PUT', "/chores/$id", ['title' => 'Teeth', 'schedule_type' => 'daily', 'required' => true, 'slots' => [
            ['id' => $slots[0]['id'], 'label' => 'Morning'], ['id' => $slots[1]['id'], 'label' => 'Evening'], ['label' => 'Bedtime'],
        ]], $this->parent);
        $creator = (new \App\Services($this->pdo, $this->clock))->creator;
        $creator->ensureDay($this->householdId, $this->kidId, '2026-12-01');
        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM chore_instances i JOIN chore_slots s ON s.id = i.slot_id WHERE s.label = 'Bedtime' AND i.local_date < '2026-12-03'"));
    }

    // ---- idempotent and safe twice ----------------------------------------------------------------------

    public function testSettlingIsIdempotentAndNeverDoubleMovesMoney(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC3);
        $this->kidList($this->kid);
        $entries = $this->countRows('SELECT COUNT(*) FROM ledger_entries');
        $instances = $this->countRows('SELECT COUNT(*) FROM chore_instances');
        $kidDays = $this->countRows('SELECT COUNT(*) FROM kid_days');
        $this->assertLedger(0, 0);

        $this->assertSame(0, $this->app->settler->settleHousehold($this->householdId), 'nothing left to do');
        for ($n = 0; $n < 3; $n++) {
            $this->call('GET', '/me', [], $this->parent);
            $this->kidList($this->kid);
        }
        $this->assertSame($entries, $this->countRows('SELECT COUNT(*) FROM ledger_entries'));
        $this->assertSame($instances, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
        $this->assertSame($kidDays, $this->countRows('SELECT COUNT(*) FROM kid_days'));
        $this->assertLedger(0, 0);
    }

    public function testTwoRequestsSettlingAtTheSameTimeDoTheWorkOnce(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC4);

        // Two independent connections (two requests) both try to settle the same household.
        $one = $this->settlerFor($this->otherConnection());
        $two = $this->settlerFor($this->otherConnection());
        $first = $one->settleHousehold($this->householdId);
        $second = $two->settleHousehold($this->householdId);
        $this->assertSame(3, $first, 'Dec 1, 2, and 3');
        $this->assertSame(0, $second, 'the second finds it all done');
        $this->assertSame(1, $this->entryCount('forfeit'), 'exactly one forfeit entry for Dec 1');
        $this->assertLedger(0, 0);
        $this->assertSame('2026-12-03', $this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
    }

    public function testASettleStartedWhileAnotherHoldsTheLockWaitsThenSkips(): void
    {
        $this->chore(['title' => 'Dishes']);
        $this->clock->set(self::DEC3);
        // Connection A locks the household row mid-settle; B (a real second connection) then settles.
        $a = $this->otherConnection();
        $a->beginTransaction();
        $a->prepare('SELECT settled_through FROM households WHERE id = ? FOR UPDATE')->execute([$this->householdId]);
        $b = $this->otherConnection();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $threw = false;
        try {
            $this->settlerFor($b)->settleHousehold($this->householdId);
        } catch (\PDOException) {
            $threw = true; // blocked by the lock: it cannot run alongside the first
        }
        $this->assertTrue($threw, 'the household row lock serializes settlement');
        $a->rollBack();
        $this->assertGreaterThan(0, $this->settlerFor($this->otherConnection())->settleHousehold($this->householdId));
    }

    public function testEveryAuthenticatedRequestSettles(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->clock->set(self::DEC3);
        $this->assertNull($this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
        $this->call('GET', '/household', [], $this->parent); // a parent request that has nothing to do with chores
        $this->assertSame('2026-12-02', $this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
    }

    public function testSettleScriptSettlesEveryHousehold(): void
    {
        $this->pdo->exec("UPDATE households SET created_at = '2026-10-01 12:00:00'");
        $cfg = sys_get_temp_dir() . '/choretest_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($cfg, '<?php return ' . var_export(['db' => test_db_settings(), 'is_test_site' => false], true) . ';');
        exec('CHORE_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/settle.php') . ' 2>&1', $out, $code);
        unlink($cfg);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertMatchesRegularExpression('/^Settled \d+ days? across 1 household\.$/', trim(implode("\n", $out)));
        $this->assertNotNull($this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
    }

    // ---- expire vs carry over -------------------------------------------------------------------------

    public function testExpiringChoresBecomeMissedAndLeaveTheKidsList(): void
    {
        $this->chore(['title' => 'Trash']); // repeating: expires by default
        $this->clock->set(self::DEC1);
        $this->assertSame(['Trash'], $this->titles($this->kidList($this->kid)));
        $this->clock->set(self::DEC2);
        $day = $this->kidList($this->kid);
        $this->assertSame('missed', $this->instanceStatuses('Trash')['2026-12-01']);
        $this->assertSame(['Trash'], $this->titles($day), "only today's Trash");
        $this->assertFalse($day['chores'][0]['overdue']);
        $this->assertSame('2026-12-02', $day['chores'][0]['for_date']);
    }

    public function testCarryOverShowsOnlyTheMostRecentUndoneInstanceAsOverdue(): void
    {
        $this->chore(['title' => 'Feed fish', 'overdue_behavior' => 'carry_over', 'value' => 2, 'currency' => 'points']);
        $this->clock->set(self::DEC4); // Dec 1, 2, 3 all went undone
        $day = $this->kidList($this->kid);
        $statuses = $this->instanceStatuses('Feed fish');
        $this->assertSame(['2026-11-30' => 'missed', '2026-12-01' => 'missed', '2026-12-02' => 'missed', '2026-12-03' => 'assigned', '2026-12-04' => 'assigned'], $statuses);
        $this->assertSame(2, count($day['chores']), "today's, plus ONE overdue (no stacking)");
        $overdue = array_values(array_filter($day['chores'], fn ($c) => $c['overdue']));
        $this->assertCount(1, $overdue);
        $this->assertSame('2026-12-03', $overdue[0]['for_date']);
        $this->assertTrue($overdue[0]['can_report'], 'an overdue chore can still be done');
    }

    public function testCompletingAnOverdueChoreEarnsTodayNotTheOriginalDay(): void
    {
        $this->chore(['title' => 'Feed fish', 'overdue_behavior' => 'carry_over', 'value' => 2, 'currency' => 'points']);
        $this->clock->set(self::DEC4);
        $overdue = array_values(array_filter($this->kidList($this->kid)['chores'], fn ($c) => $c['overdue']))[0];
        [, $body] = $this->done($overdue['id']);
        $this->assertTrue($body['chore']['is_late']);
        $this->approve($overdue['id']);
        $this->assertSame('2026-12-04', $this->pdo->query("SELECT local_date FROM ledger_entries WHERE entry_type = 'earning'")->fetchColumn());
        $this->assertLedger(0, 2);
        $this->assertSame(['points' => 2, 'money' => 0], $this->kidList($this->kid)['earned_today']);
        $day = $this->kidList($this->kid);
        $this->assertSame(['Feed fish', 'Feed fish'], $this->titles($day), 'the overdue one shows (as done) today');
    }

    public function testExpiredRepeatingChoreCannotBeDoneLater(): void
    {
        $this->chore(['title' => 'Trash']);
        $this->clock->set(self::DEC1);
        $old = $this->row('Trash')['id'];
        $this->clock->set(self::DEC2);
        [$status] = $this->done($old);
        $this->assertSame(409, $status);
    }

    public function testOneTimeChoreCarriesOverAsASingleOverdueInstance(): void
    {
        $this->chore(['title' => 'Rake', 'schedule_type' => 'one_off', 'due_date' => '2026-12-02']);
        $this->clock->set(self::DEC5);
        $day = $this->kidList($this->kid);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
        $this->assertSame(['Rake'], $this->titles($day));
        $this->assertTrue($day['chores'][0]['overdue']);
        $this->assertSame('assigned', $this->instanceStatuses('Rake')['2026-11-30']);
    }

    public function testOneTimeChoreSetToExpireBecomesMissedAfterItsDueDate(): void
    {
        $this->chore(['title' => 'Rake', 'schedule_type' => 'one_off', 'due_date' => '2026-12-02', 'overdue_behavior' => 'expire']);
        $this->clock->set(self::DEC2); // still its due date
        $this->assertSame(['Rake'], $this->titles($this->kidList($this->kid)));
        $this->clock->set(self::DEC3);
        $this->assertSame([], $this->kidList($this->kid)['chores']);
        $this->assertSame('missed', $this->instanceStatuses('Rake')['2026-11-30']);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
    }

    public function testReportedButUnapprovedChoresAreNotMissed(): void
    {
        $this->chore(['title' => 'Trash']);
        $this->clock->set(self::DEC1);
        $this->done($this->row('Trash')['id']);
        $this->clock->set(self::DEC3);
        $this->kidList($this->kid);
        $this->assertSame('reported_done', $this->instanceStatuses('Trash')['2026-12-01']);
    }

    // ---- end of day other than midnight, other time zones ---------------------------------------------

    public function testEndOfDayAtTwoAmDelaysSettlement(): void
    {
        $this->call('PUT', '/household', ['day_end_time' => '02:00'], $this->parent);
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');

        $this->clock->set('2026-12-02 06:30:00'); // 1:30 AM on Dec 2 in New York: still Dec 1's day
        $day = $this->kidList($this->kid);
        $this->assertSame('2026-12-01', $day['today']);
        $this->assertSame('locked', $this->dayStatus('2026-12-01'), 'not settled yet');
        $this->doAndApprove('Make bed'); // late-night: still counts for Dec 1
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 15);
        $this->assertSame('2026-12-01', $this->pdo->query("SELECT local_date FROM ledger_entries WHERE entry_type = 'earning' ORDER BY id DESC LIMIT 1")->fetchColumn());

        $this->clock->set('2026-12-02 07:00:00'); // 2:00 AM: Dec 2 begins and Dec 1 is settled
        $this->assertSame('2026-12-02', $this->kidList($this->kid)['today']);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertSame('2026-12-01', $this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
    }

    public function testEndOfDayAtTwoAmForfeitsOnlyAfterTwoAm(): void
    {
        $this->call('PUT', '/household', ['day_end_time' => '02:00'], $this->parent);
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->clock->set('2026-12-02 06:59:00');
        $this->kidList($this->kid);
        $this->assertSame('locked', $this->dayStatus('2026-12-01'));
        $this->clock->set('2026-12-02 07:00:00');
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
    }

    public function testADifferentTimeZoneSettlesAtItsOwnMidnight(): void
    {
        $this->clock->set('2026-11-30 03:00:00'); // noon in Tokyo
        [$tokyo] = $this->registerParent('tokyo@example.com', 'Asia/Tokyo');
        [$yuki, $link] = $this->createKid($tokyo, 'Yuki');
        $this->createChore($tokyo, [$yuki], ['title' => 'Make bed', 'required' => true]);
        $kid = $this->kidToken($link);

        $this->clock->set('2026-12-01 14:59:59'); // 23:59:59 on Dec 1 in Tokyo (but only 9:59 AM in New York)
        $this->assertSame('2026-12-01', $this->kidList($kid)['today']);
        $this->assertSame('locked', $this->dayStatus('2026-12-01', $yuki));
        $this->clock->set('2026-12-01 15:00:00'); // midnight in Tokyo
        $this->assertSame('2026-12-02', $this->kidList($kid)['today']);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01', $yuki));
    }

    public function testDaylightSavingDayStillSettlesOnce(): void
    {
        // US spring-forward: Sunday 2027-03-14 has 23 hours. Settling the days around it must not skip or repeat a day.
        $this->clock->set('2027-03-12 15:00:00');
        [$p] = $this->registerParent('dst@example.com');
        [$k, $link] = $this->createKid($p, 'Dee');
        $this->createChore($p, [$k], ['title' => 'Bed', 'required' => true]);
        $this->clock->set('2027-03-16 15:00:00');
        $this->kidList($this->kidToken($link));
        foreach (['2027-03-13', '2027-03-14', '2027-03-15'] as $d) {
            $this->assertSame('forfeited', $this->dayStatus($d, $k), $d);
        }
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM kid_days WHERE kid_id = $k AND local_date = '2027-03-14'"));
    }
}
