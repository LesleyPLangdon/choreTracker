<?php
declare(strict_types=1);

namespace Tests;

/** Locking, unlocking, forfeiting, and waiting — the heart of Phase 3. */
final class RequiredChoresTest extends RulesTestCase
{
    private function bedAndDishes(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
    }

    // ---- reported but not approved: waiting -------------------------------------------------------------

    public function testRequiredReportedButNotApprovedDayWaitsThenApprovingUnlocks(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC1);
        $bed = $this->row('Make bed')['id'];
        $dishes = $this->row('Dishes')['id'];
        $this->done($bed);
        $this->done($dishes);
        $this->approve($dishes);
        $this->assertLedger(5, 0, 'points', 'approved earnings stay locked while a required chore is not approved');
        $this->assertSame('locked', $this->dayStatus('2026-12-01'));

        $this->clock->set(self::DEC2); // the day has ended; the next request settles it
        $this->kidList($this->kid);
        $this->assertSame('waiting', $this->dayStatus('2026-12-01'), 'reported, so not forfeited');
        $this->assertLedger(5, 0);
        $items = $this->approvals();
        $this->assertSame(1, $items['count'], 'the badge counts the chore to approve, not the day summary');
        $this->assertSame(['chore', 'day_waiting'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['items'][1]['to_check']);
        $this->assertSame(5, $items['items'][1]['locked']['points']);

        $this->approve($bed);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 15, 'points', 'both earnings moved to Spending');
        $this->assertSame(0, $this->approvals()['count'] + count(array_filter($this->approvals()['items'], fn ($i) => $i['type'] === 'day_waiting')));
        $this->approve($bed); // repeating the request moves nothing more
        $this->assertLedger(0, 15);
    }

    public function testSendingBackAfterTheDayEndedForfeitsIt(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC1);
        $bed = $this->row('Make bed')['id'];
        $dishes = $this->row('Dishes')['id'];
        $this->done($bed);
        $this->done($dishes);
        $this->approve($dishes);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('waiting', $this->dayStatus('2026-12-01'));

        $this->sendBack($bed);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0, 'points', 'pending offset, nothing reached Spending');
        $this->assertSame(1, $this->entryCount('forfeit'));
    }

    // ---- unlocking during the day ----------------------------------------------------------------------

    public function testAllRequiredApprovedDuringTheDayUnlocksAndLaterApprovalsGoStraightToSpending(): void
    {
        $this->bedAndDishes();
        $this->chore(['title' => 'Trash', 'value' => 3, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertLedger(5, 0);
        $this->doAndApprove('Make bed'); // the last required slot is approved: the day unlocks
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 15);
        $this->doAndApprove('Trash'); // approved later the same day: straight to Spending
        $this->assertLedger(0, 18);
        $this->assertSame(3, $this->entryCount('earning'));
        $this->assertSame(4, $this->entryCount('unlock'), 'two moves (the day unlocking, then the later approval), each a pending-out and a spending-in entry');
    }

    public function testSendingBackDuringTheDayJustLetsTheKidRedoIt(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC1);
        $bed = $this->row('Make bed')['id'];
        $this->done($bed);
        $this->sendBack($bed);
        $this->assertSame('locked', $this->dayStatus('2026-12-01'));
        $this->done($bed);
        $this->approve($bed);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 10);
    }

    public function testALateRequiredChoreStillUnlocksTheDay(): void
    {
        $this->chore(['title' => 'Dressed', 'value' => 4, 'currency' => 'points', 'required' => true, 'slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $this->clock->set(self::DEC1); // 10 AM: past due-by
        $id = $this->row('Dressed')['id'];
        [, $body] = $this->done($id);
        $this->assertTrue($body['chore']['is_late']);
        $this->approve($id);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 4);
    }

    // ---- forfeit ----------------------------------------------------------------------------------------

    public function testRequiredNeverReportedForfeitsAtEndOfDay(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertLedger(5, 0);

        $this->clock->set(self::DEC2);
        $day = $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0, 'points', 'pending offset by a forfeit entry; Spending unchanged');
        $forfeit = $this->pdo->query("SELECT * FROM ledger_entries WHERE entry_type = 'forfeit'")->fetchAll();
        $this->assertCount(1, $forfeit);
        $this->assertSame(-5, (int) $forfeit[0]['amount']);
        $this->assertSame('pending', $forfeit[0]['bucket']);
        $this->assertSame('2026-12-01', $forfeit[0]['local_date']);
        $this->assertSame([['type' => 'forfeited', 'date' => '2026-12-01']], $day['notices']);
        $this->assertSame(['2026-12-01' => 'missed', '2026-12-02' => 'assigned'], array_slice($this->instanceStatuses('Make bed'), 1, 2));
    }

    public function testApprovingSomethingReportedOnAForfeitedDayForfeitsItImmediately(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC1);
        $dishes = $this->row('Dishes')['id'];
        $this->done($dishes); // reported on Dec 1, but the required bed is never done
        $this->clock->set(self::DEC3);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0);

        $this->approve($dishes); // approved late: its earning belongs to Dec 1, which is forfeited
        $this->assertLedger(0, 0, 'points', 'the earning is written and offset at once; it never reaches Spending');
        $this->assertSame(1, $this->entryCount('earning'));
        $this->assertSame(1, $this->entryCount('forfeit'));
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'), 'and the day stays forfeited');
    }

    public function testEveryDayStartsFreshAfterAForfeit(): void
    {
        $this->bedAndDishes();
        $this->clock->set(self::DEC2); // Dec 1 forfeits (nothing done), then Dec 2 is a new day
        $this->doAndApprove('Make bed');
        $this->doAndApprove('Dishes');
        $this->assertSame('unlocked', $this->dayStatus('2026-12-02'));
        $this->assertLedger(0, 15);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
    }

    // ---- days without required chores ---------------------------------------------------------------

    public function testNoRequiredChoresMeansEarningsGoToSpendingAsApproved(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->chore(['title' => 'Walk', 'value' => 50, 'currency' => 'money']);
        $this->clock->set(self::DEC1);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status'], 'unlocked from the start');
        $this->doAndApprove('Dishes');
        $this->assertLedger(0, 5);
        $this->doAndApprove('Walk');
        $this->assertLedger(0, 50, 'money');
        $this->assertLedger(0, 5, 'points');
    }

    // ---- which chores are required ---------------------------------------------------------------------

    public function testEverySlotOfARequiredChoreIsRequired(): void
    {
        $this->chore(['title' => 'Brush teeth', 'required' => true, 'value' => 6, 'currency' => 'points',
            'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $this->clock->set(self::DEC1);
        $day = $this->kidList($this->kid);
        $this->assertSame(2, $this->countRows("SELECT COUNT(*) FROM chore_instances WHERE required_for_date = '2026-12-01'"));
        $this->assertSame('locked', $day['day']['status']);
        $this->assertCount(2, $day['day']['required_left']);

        // Only Morning gets done: the day forfeits.
        $this->doAndApprove('Brush teeth', 'Morning');
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status'], 'Evening is still required');
        $this->assertLedger(6, 0);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0);

        // Both reported but only one approved: waiting.
        $this->doAndApprove('Brush teeth', 'Morning');
        $evening = $this->row('Brush teeth', 'Evening')['id'];
        $this->done($evening);
        $this->clock->set(self::DEC3);
        $this->kidList($this->kid);
        $this->assertSame('waiting', $this->dayStatus('2026-12-02'));
    }

    public function testRequiredOneTimeChoreGatesOnlyItsDueDate(): void
    {
        $this->chore(['title' => 'Rake', 'schedule_type' => 'one_off', 'due_date' => '2026-12-03', 'required' => true, 'value' => 20, 'currency' => 'points']);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);

        $this->clock->set(self::DEC1);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status'], 'not due yet: does not lock Dec 1');
        $this->doAndApprove('Dishes');
        $this->assertLedger(0, 5);

        $this->clock->set(self::DEC3); // the due date
        $day = $this->kidList($this->kid);
        $this->assertSame('locked', $day['day']['status']);
        $this->assertSame('Rake', $day['day']['required_left'][0]['title']);
        $this->doAndApprove('Dishes');
        $this->assertLedger(5, 5, 'points', "Dec 3's dishes wait for the required rake");

        $this->clock->set(self::DEC4); // Rake was never done by the end of its due date
        $day = $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-03'));
        $this->assertLedger(0, 5);
        $this->assertSame('unlocked', $day['day']['status'], 'Dec 4 is not gated by the old one-time chore');
        $rake = $this->row('Rake', null, $day);
        $this->assertTrue($rake['overdue'], 'it carries over as overdue like any one-time chore');
        $this->assertFalse($rake['required']);

        $this->doAndApprove('Rake'); // completing it today earns today, and today is unlocked
        $this->assertLedger(0, 25);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-03'), "the old day isn't revived");
        $this->assertSame('2026-12-04', $this->pdo->query("SELECT local_date FROM ledger_entries WHERE entry_type = 'earning' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testRequiredOneTimeChoreDoneEarlyAndApprovedSatisfiesItsDay(): void
    {
        $this->chore(['title' => 'Rake', 'schedule_type' => 'one_off', 'due_date' => '2026-12-03', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Rake'); // a day early
        $this->clock->set(self::DEC3);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status']);
        $this->doAndApprove('Dishes');
        $this->assertLedger(0, 5);
        $this->clock->set(self::DEC4);
        $this->kidList($this->kid);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-03'));
    }

    public function testRequiredOneTimeChoreWithNoDueDateGatesTheFirstDayItCanBeRequired(): void
    {
        $this->chore(['title' => 'Room', 'schedule_type' => 'one_off', 'required' => true]); // created Nov 30
        $this->assertSame('2026-12-01', $this->pdo->query("SELECT required_for_date FROM chore_instances WHERE chore_id IN (SELECT id FROM chores WHERE title = 'Room')")->fetchColumn(),
            'takes effect the next household day');
        $this->clock->set(self::DEC1);
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status']);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status'], 'and it gates nothing after that');
    }

    // ---- edits take effect tomorrow -------------------------------------------------------------------

    public function testMakingAChoreRequiredMidDayDoesNotRelockAndStartsTomorrow(): void
    {
        $bed = $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Make bed');
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 10);

        $this->clock->set('2026-12-01 20:00:00'); // 3 PM: now make it required
        $slots = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0]['slots'];
        [$status] = $this->call('PUT', "/chores/$bed", [
            'title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'schedule_type' => 'daily', 'required' => true,
            'slots' => [['id' => $slots[0]['id'], 'label' => 'Anytime']],
        ], $this->parent);
        $this->assertSame(200, $status);
        $today = $this->kidList($this->kid);
        $this->assertSame('unlocked', $today['day']['status'], "today never re-locks");
        $this->assertNull($this->pdo->query("SELECT required_for_date FROM chore_instances WHERE local_date = '2026-12-01'")->fetchColumn());

        $this->clock->set(self::DEC2);
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status'], 'required from tomorrow');
        $this->assertSame('2026-12-02', $this->pdo->query("SELECT required_for_date FROM chore_instances WHERE local_date = '2026-12-02'")->fetchColumn());
    }

    public function testAddingANewRequiredChoreMidDayStartsTomorrow(): void
    {
        $this->clock->set(self::DEC1);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status']);
        $this->chore(['title' => 'Backpack', 'required' => true]);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status']);
        $this->clock->set(self::DEC2);
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status']);
    }

    public function testMakingAChoreNotRequiredMidDayAlsoWaitsUntilTomorrow(): void
    {
        $bed = $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->clock->set(self::DEC1);
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status']);
        $slots = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0]['slots'];
        $this->call('PUT', "/chores/$bed", ['title' => 'Make bed', 'schedule_type' => 'daily', 'required' => false, 'slots' => [['id' => $slots[0]['id'], 'label' => 'Anytime']]], $this->parent);
        $this->assertSame('locked', $this->kidList($this->kid)['day']['status'], "today's instance is still required");
        $this->clock->set(self::DEC2);
        $this->assertSame('unlocked', $this->kidList($this->kid)['day']['status']);
    }

    // ---- what the kid sees ----------------------------------------------------------------------------

    public function testKidSeesLockedWaitingAndUnlockedStates(): void
    {
        $this->chore(['title' => 'Brush teeth', 'required' => true, 'value' => 6, 'currency' => 'points',
            'slots' => [['label' => 'Morning'], ['label' => 'Evening', 'available_from' => '18:00']]]);
        $this->clock->set(self::DEC1);
        $day = $this->kidList($this->kid);
        $this->assertSame('locked', $day['day']['status']);
        $this->assertEqualsCanonicalizing(
            [['title' => 'Brush teeth', 'slot_label' => 'Morning', 'opens_at' => null], ['title' => 'Brush teeth', 'slot_label' => 'Evening', 'opens_at' => '18:00']],
            $day['day']['required_left']
        );

        $this->doAndApprove('Brush teeth', 'Morning');
        $day = $this->kidList($this->kid);
        $this->assertSame([['title' => 'Brush teeth', 'slot_label' => 'Evening', 'opens_at' => '18:00']], $day['day']['required_left']);
        $this->assertSame(['points' => 6, 'money' => 0], $day['day']['locked'], 'the locked amount is shown (each slot earns the chore value)');
        $this->assertSame(['points' => 0, 'money' => 0], $day['spending']);

        $this->clock->set('2026-12-01 23:30:00'); // 6:30 PM
        $evening = $this->row('Brush teeth', 'Evening')['id'];
        $this->done($evening);
        $day = $this->kidList($this->kid);
        $this->assertSame('waiting', $day['day']['status']);
        $this->assertSame([], $day['day']['required_left']);

        $this->approve($evening);
        $day = $this->kidList($this->kid);
        $this->assertSame('unlocked', $day['day']['status']);
        $this->assertSame(['points' => 12, 'money' => 0], $day['spending']);
        $this->assertSame(['points' => 0, 'money' => 0], $day['day']['locked']);
    }

    public function testForfeitNoteAppearsOnceUntilTheKidDismissesIt(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->clock->set(self::DEC2);
        $day = $this->kidList($this->kid);
        $this->assertSame([['type' => 'forfeited', 'date' => '2026-12-01']], $day['notices']);
        $this->assertSame([['type' => 'forfeited', 'date' => '2026-12-01']], $this->kidList($this->kid)['notices'], 'stays until dismissed');
        [$status] = $this->call('POST', '/kid/days/2026-12-01/ack', [], $this->kid);
        $this->assertSame(200, $status);
        $this->assertSame([], $this->kidList($this->kid)['notices']);
    }

    public function testRequiredFlagShowsOnTheChoresList(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->chore(['title' => 'Dishes']);
        $list = $this->call('GET', '/chores', [], $this->parent)[1]['chores'];
        $this->assertSame(['Dishes' => false, 'Make bed' => true], array_column($list, 'required', 'title'));
    }
}
