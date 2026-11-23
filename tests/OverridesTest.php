<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Database;

/** Back-fill, overrides, balance adjustments, history, and the simulated-time clock. */
final class OverridesTest extends RulesTestCase
{
    /** Bed (required, 10) + Dishes (5): on Dec 1 only Dishes is done+approved, so Dec 1 is forfeited by Dec 2. */
    private function forfeitedDay(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0);
    }

    private function post(string $path, array $body = []): array
    {
        return $this->call('POST', $path, $body, $this->parent);
    }

    private function history(): array
    {
        [$status, $body] = $this->call('GET', "/kids/{$this->kidId}/history", [], $this->parent);
        $this->assertSame(200, $status, json_encode($body));
        return $body;
    }

    private function historyDay(string $date): array
    {
        foreach ($this->history()['days'] as $d) {
            if ($d['date'] === $date) {
                return $d;
            }
        }
        $this->fail("no history day $date");
    }

    // ---- back-fill ----------------------------------------------------------------------------------

    public function testBackFillOnTheLastMissingRequiredChoreUnlocksAForfeitedDay(): void
    {
        $this->forfeitedDay();
        $slotId = $this->historyDay('2026-12-01')['chores'][0]['slot_id'];
        $bed = array_values(array_filter($this->historyDay('2026-12-01')['chores'], fn ($c) => $c['title'] === 'Make bed'))[0];
        $this->assertTrue($bed['can_back_fill']);
        [$status, $body] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $bed['slot_id'], 'date' => '2026-12-01', 'note' => 'We did it, forgot to tap']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('approved', $body['chore']['status']);

        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 15, 'points', 'forfeit reversed, then both earnings moved to Spending');
        $row = $this->pdo->query("SELECT is_back_filled, approved_by_parent_id FROM chore_instances WHERE id = {$bed['id']}")->fetch();
        $this->assertSame(1, (int) $row['is_back_filled']);
        $this->assertNotNull($row['approved_by_parent_id']);
        // Offsetting entries, nothing edited: earning(+5), forfeit(-5), forfeit reversal(+5), earning(+10), unlocks.
        $this->assertSame(0, (int) $this->pdo->query("SELECT SUM(amount) FROM ledger_entries WHERE entry_type = 'forfeit'")->fetchColumn(), 'forfeit fully reversed');
        $this->assertSame(2, $this->entryCount('forfeit'));
        $this->assertSame('2026-12-01', $this->pdo->query("SELECT local_date FROM ledger_entries WHERE chore_instance_id = {$bed['id']}")->fetchColumn(), 'earned on the day it was filled in');
        $this->assertSame(['back_fill'], array_column($this->historyDay('2026-12-01')['events'], 'action'));
        $this->assertSame('Mom', $this->historyDay('2026-12-01')['events'][0]['by']);
    }

    public function testBackFillCreatesAMissingInstance(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $slotId = (int) $this->pdo->query('SELECT id FROM chore_slots')->fetchColumn();
        $this->clock->set(self::DEC4);
        $this->kidList($this->kid);
        $this->pdo->exec("DELETE FROM chore_instances WHERE local_date = '2026-12-02'"); // as if it had never been created
        [$status, $body] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-02']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('approved', $this->instanceStatuses('Dishes')['2026-12-02']);
        $this->assertLedger(0, 5);
    }

    public function testBackFillIsLimitedToSevenDaysBackAndPastDays(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $slotId = (int) $this->pdo->query('SELECT id FROM chore_slots')->fetchColumn();
        $this->clock->set('2026-12-10 15:00:00'); // Thursday Dec 10
        $this->kidList($this->kid);
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-02']);
        $this->assertSame(422, $status, '8 days back is too far');
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-03']);
        $this->assertSame(200, $status, '7 days back is fine');
        [$status, $body] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-10']);
        $this->assertSame(422, $status, 'today uses Mark done');
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-11']);
        $this->assertSame(422, $status, 'the future');
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-03']);
        $this->assertSame(409, $status, 'already approved');
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => 'nonsense']);
        $this->assertSame(422, $status);
        $this->assertLedger(0, 5);
    }

    public function testBackFillRefusesAChoreNotScheduledThatDay(): void
    {
        $this->chore(['title' => 'Mondays', 'days' => [1]]);
        $slotId = (int) $this->pdo->query('SELECT id FROM chore_slots')->fetchColumn();
        $this->clock->set(self::DEC4);
        $this->kidList($this->kid);
        [$status] = $this->post("/kids/{$this->kidId}/back-fill", ['slot_id' => $slotId, 'date' => '2026-12-02']); // a Wednesday
        $this->assertSame(422, $status);
    }

    // ---- overrides ----------------------------------------------------------------------------------

    public function testUnlockAForfeitedDayReversesTheForfeitAndMovesMoney(): void
    {
        $this->forfeitedDay();
        [$status, $body] = $this->post("/kids/{$this->kidId}/days/2026-12-01/unlock", ['note' => 'Was sick']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 5);
        $day = $this->historyDay('2026-12-01');
        $this->assertTrue($day['manual']);
        $this->assertSame([['action' => 'unlock', 'note' => 'Was sick', 'by' => 'Mom']], array_map(fn ($e) => array_intersect_key($e, ['action' => 1, 'note' => 1, 'by' => 1]), $day['events']));
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-01/unlock");
        $this->assertSame(409, $status, 'already unlocked');

        // A manual unlock sticks: nothing later flips it back.
        $this->clock->set(self::DEC4);
        $this->kidList($this->kid);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
    }

    public function testUnlockAWaitingDay(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->clock->set(self::DEC1);
        $bed = $this->row('Make bed')['id'];
        $this->done($bed);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('waiting', $this->dayStatus('2026-12-01'));
        $this->post("/kids/{$this->kidId}/days/2026-12-01/unlock");
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->approve($bed); // earning now goes straight to Spending
        $this->assertLedger(0, 10);
    }

    public function testForfeitAnUnlockedDayTakesTheMoneyBackOutOfSpending(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertLedger(0, 5);
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-01/forfeit", ['note' => 'Fibbing about it']);
        $this->assertSame(200, $status);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0, 'points', 'offsetting entry; history untouched');
        $this->assertSame(1, $this->entryCount('earning'));
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-01/forfeit");
        $this->assertSame(409, $status);
    }

    public function testForfeitIsRefusedIfTheMoneyWasAlreadySpentDown(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -3, 'note' => 'Bought a sticker']);
        [$status, $body] = $this->post("/kids/{$this->kidId}/days/2026-12-01/forfeit");
        $this->assertSame(409, $status);
        $this->assertStringContainsString('spent', $body['error']);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 2);
    }

    public function testForfeitingTodayForfeitsLaterEarningsToo(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->chore(['title' => 'Trash', 'value' => 3, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->post("/kids/{$this->kidId}/days/2026-12-01/forfeit");
        $this->doAndApprove('Dishes');
        $this->assertLedger(0, 0, 'points', 'earned on a forfeited day: offset at once');
    }

    public function testUndoAnApprovalOffsetsTheEarning(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $id = $this->row('Dishes')['id'];
        $this->done($id);
        $this->approve($id);
        $this->assertLedger(0, 5);

        [$status, $body] = $this->post("/instances/$id/undo-approval", ['note' => 'Not actually done']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('reported_done', $body['chore']['status']);
        $this->assertLedger(0, 0);
        $this->assertSame(1, $this->approvals()['count'], 'back in the approvals list');
        $this->assertSame(2, $this->entryCount('earning'), 'the original and an offsetting one — nothing deleted or edited');
        $this->assertSame(['undo_approval'], array_column($this->historyDay('2026-12-01')['events'], 'action'));

        $this->approve($id); // approving again earns again
        $this->assertLedger(0, 5);
        [$status] = $this->post("/instances/$id/undo-approval");
        $this->assertSame(200, $status);
        [$status] = $this->post("/instances/$id/undo-approval");
        $this->assertSame(409, $status, 'no longer approved');
    }

    public function testUndoAnApprovalOnAWaitingDayMovesTheDayBackToWaiting(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->clock->set(self::DEC1);
        $id = $this->row('Make bed')['id'];
        $this->done($id);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('waiting', $this->dayStatus('2026-12-01'));
        $this->approve($id);
        $this->assertLedger(0, 10);
        // The money is already in Spending, and the day stays unlocked (nothing re-locks); undoing offsets that money.
        $this->post("/instances/$id/undo-approval");
        $this->assertLedger(0, 0);
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
    }

    public function testUndoAnApprovalIsRefusedWhenTheMoneyHasBeenSpent(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $id = $this->row('Dishes')['id'];
        $this->done($id);
        $this->approve($id);
        $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -4, 'note' => 'Spent']);
        [$status] = $this->post("/instances/$id/undo-approval");
        $this->assertSame(409, $status);
        $this->assertLedger(0, 1);
    }

    public function testUndoApprovalOfAPendingEarningOffsetsPending(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $id = $this->row('Dishes')['id'];
        $this->done($id);
        $this->approve($id);
        $this->assertLedger(5, 0);
        $this->post("/instances/$id/undo-approval");
        $this->assertLedger(0, 0);
    }

    public function testUndoAnApprovalOnAForfeitedDayOffsetsTheForfeitToo(): void
    {
        $this->forfeitedDay(); // Dishes (5) approved on Dec 1, then the day forfeited
        $dishes = (int) $this->pdo->query("SELECT i.id FROM chore_instances i JOIN chores c ON c.id = i.chore_id WHERE c.title = 'Dishes' AND i.local_date = '2026-12-01'")->fetchColumn();
        [$status] = $this->post("/instances/$dishes/undo-approval");
        $this->assertSame(200, $status);
        $this->assertLedger(0, 0);
        $this->assertSame(0, (int) $this->pdo->query("SELECT SUM(amount) FROM ledger_entries WHERE entry_type = 'forfeit'")->fetchColumn());
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
    }

    public function testClearALateFlag(): void
    {
        $this->chore(['title' => 'Dressed', 'slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $this->clock->set(self::DEC1);
        $id = $this->row('Dressed')['id'];
        $this->assertTrue($this->done($id)[1]['chore']['is_late']);
        $this->approve($id);
        [$status, $body] = $this->post("/instances/$id/clear-late", ['note' => 'Bus was late']);
        $this->assertSame(200, $status);
        $this->assertFalse($body['chore']['is_late']);
        $this->assertSame(['clear_late'], array_column($this->historyDay('2026-12-01')['events'], 'action'));
        [$status] = $this->post("/instances/$id/clear-late");
        $this->assertSame(409, $status);
    }

    public function testOverridesReachOnlySevenDaysBack(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $old = $this->row('Dishes')['id'];
        $this->done($old);
        $this->approve($old);
        $this->clock->set('2026-12-09 15:00:00'); // Dec 1 is now 8 days back
        $this->kidList($this->kid);
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-01/forfeit");
        $this->assertSame(422, $status);
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-01/unlock");
        $this->assertSame(422, $status);
        [$status] = $this->post("/instances/$old/undo-approval");
        $this->assertSame(422, $status);
        [$status] = $this->post("/kids/{$this->kidId}/days/2026-12-02/forfeit");
        $this->assertSame(200, $status, '7 days back is fine');
    }

    // ---- balance adjustment -----------------------------------------------------------------------------

    public function testAdjustBalanceAddsAndSubtractsButNeverBelowZero(): void
    {
        $this->clock->set(self::DEC1);
        [$status, $body] = $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 20, 'note' => 'Birthday bonus']);
        $this->assertSame(200, $status);
        $this->assertSame(['points' => 20, 'money' => 0], $body['spending']);
        [$status, $body] = $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'money', 'amount' => 500, 'note' => 'Allowance']);
        $this->assertSame(['points' => 20, 'money' => 500], $body['spending']);
        [$status] = $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -21, 'note' => 'Too much']);
        $this->assertSame(422, $status);
        [$status, $body] = $this->post("/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -20, 'note' => 'Spent it']);
        $this->assertSame(200, $status);
        $this->assertSame(0, $body['spending']['points']);
        $this->assertLedger(0, 0, 'points');
        $this->assertLedger(0, 500, 'money');

        $e = $this->pdo->query("SELECT * FROM ledger_entries WHERE entry_type = 'parent_adjustment' ORDER BY id")->fetchAll();
        $this->assertCount(3, $e);
        $this->assertSame('spending', $e[0]['bucket']);
        $this->assertSame('parent', $e[0]['created_by_type']);
        $this->assertSame('Birthday bonus', $e[0]['note']);

        $adj = $this->history()['adjustments'];
        $this->assertSame(['Spent it', 'Allowance', 'Birthday bonus'], array_column($adj, 'note'));
        $this->assertSame('Mom', $adj[0]['by']);
    }

    public function testAdjustBalanceValidation(): void
    {
        foreach ([
            ['currency' => 'points', 'amount' => 0, 'note' => 'x'], ['currency' => 'points', 'amount' => 5, 'note' => ''],
            ['currency' => 'gems', 'amount' => 5, 'note' => 'x'], ['currency' => 'points', 'amount' => 1.5, 'note' => 'x'],
            ['currency' => 'points', 'amount' => 'ten', 'note' => 'x'],
        ] as $bad) {
            [$status] = $this->post("/kids/{$this->kidId}/adjust", $bad);
            $this->assertSame(422, $status, json_encode($bad));
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM ledger_entries'));
    }

    // ---- history --------------------------------------------------------------------------------------

    public function testHistoryShowsTodayAndSevenDaysWithStatusesChoresAndEarnings(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->chore(['title' => 'Dressed', 'slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->doAndApprove('Dressed');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);

        $h = $this->history();
        $this->assertCount(8, $h['days']);
        $this->assertSame(['2026-12-02', '2026-12-01', '2026-11-30'], array_slice(array_column($h['days'], 'date'), 0, 3));
        $dec1 = $this->historyDay('2026-12-01');
        $this->assertSame('forfeited', $dec1['status']);
        $this->assertSame(['points' => 5, 'money' => 0], $dec1['earned']);
        $byTitle = array_column($dec1['chores'], null, 'title');
        $this->assertSame('missed', $byTitle['Make bed']['status']);
        $this->assertTrue($byTitle['Make bed']['required']);
        $this->assertSame('approved', $byTitle['Dishes']['status']);
        $this->assertTrue($byTitle['Dressed']['is_late']);
        $this->assertTrue($byTitle['Dressed']['can_clear_late']);
        $this->assertTrue($dec1['can_unlock']);
        $this->assertFalse($dec1['can_forfeit']);
        $this->assertTrue($h['days'][0]['is_today']);
        $this->assertSame('unlocked', $this->historyDay('2026-11-30')['status']);
        $this->assertSame(['points' => 0, 'money' => 0], $h['spending']);
    }

    public function testHistoryAndOverridesStayInsideTheHousehold(): void
    {
        $this->forfeitedDay();
        [$other] = $this->registerParent('other@example.com');
        [$otherKid, $otherLink] = $this->createKid($other, 'Zed');
        foreach ([
            ['GET', "/kids/{$this->kidId}/history", []], ['POST', "/kids/{$this->kidId}/days/2026-12-01/unlock", []],
            ['POST', "/kids/{$this->kidId}/days/2026-12-01/forfeit", []], ['POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 5, 'note' => 'x']],
            ['POST', "/kids/{$this->kidId}/back-fill", ['slot_id' => 1, 'date' => '2026-12-01']],
        ] as [$m, $p, $b]) {
            [$status] = $this->call($m, $p, $b, $other);
            $this->assertSame(404, $status, "$m $p");
        }
        $kidToken = $this->kidToken($otherLink);
        foreach ([['GET', "/kids/$otherKid/history"], ['POST', "/kids/$otherKid/adjust"], ['POST', "/kids/$otherKid/days/2026-12-01/unlock"]] as [$m, $p]) {
            [$status] = $this->call($m, $p, [], $kidToken);
            $this->assertSame(401, $status, "kid token on $m $p");
        }
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertLedger(0, 0);
    }

    // ---- simulated time --------------------------------------------------------------------------------

    public function testSimulatedNowOnlyWorksOnTestSitesAndAcceptsALocalTimeZone(): void
    {
        $sim = '2026-12-01 23:59:00 America/New_York';
        $app = App::fromConfig(test_config(['simulated_now' => $sim]));
        $this->assertSame('2026-12-02 04:59:00', $app->clock->nowSql());

        $notTest = new \App\Config(['db' => test_db_settings(), 'is_test_site' => false, 'simulated_now' => $sim]);
        $real = App::fromConfig($notTest);
        $this->assertNotSame('2026-12-02 04:59:00', $real->clock->nowSql(), 'ignored when this is not a test site');
    }

    public function testClockEndpointReportsSimulatedTimeFromTheApi(): void
    {
        $app = App::fromConfig(test_config(['simulated_now' => '2026-12-01 23:59:00 America/New_York']));
        $req = fn (string $method, string $path, array $body = [], ?string $token = null) => $app->handle(new \App\Http\Request($method, $path, $body, $token ? ['authorization' => "Bearer $token"] : []));
        $register = json_decode($req('POST', '/register', [
            'email' => 'sim@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'Sim', 'household_name' => 'Sims', 'timezone' => 'America/New_York',
        ])->json(), true);

        $anon = json_decode($req('GET', '/clock')->json(), true);
        $this->assertTrue($anon['simulated']);
        $this->assertSame('2026-12-02 04:59:00', $anon['utc']);
        $this->assertNull($anon['local']);
        $mine = json_decode($req('GET', '/clock', [], $register['token'])->json(), true);
        $this->assertSame('2026-12-01 23:59:00', $mine['local']);
        $this->assertSame('America/New_York', $mine['timezone']);
        $this->assertSame('2026-12-01', $register['household']['today']);

        [, $normal] = $this->call('GET', '/clock');
        $this->assertTrue($normal['simulated'] === false, 'the shared test clock is not "simulated" unless config says so');
    }
}
