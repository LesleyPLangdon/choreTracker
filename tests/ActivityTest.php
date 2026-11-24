<?php
declare(strict_types=1);

namespace Tests;

use App\Ledger;

/** The plain-language activity list, built from the ledger (never stored as text). */
final class ActivityTest extends MoneyTestCase
{
    private function parentActivity(): array
    {
        return $this->ok($this->parentCall('GET', "/kids/{$this->kidId}/activity"))['activity'];
    }

    private function kidActivity(): array
    {
        return array_column($this->ok($this->kidCall('GET', '/kid/activity'))['activity'], 'text');
    }

    public function testUnlockWithTheSplitIsWordedFromTheLedger(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Feed fish', 'value' => 5, 'currency' => 'points', 'auto_approve' => true]);
        $this->clock->set(self::DEC1); // Tuesday: the chores above (made Monday) are required from today
        $this->doAndApprove('Feed fish');
        $this->doAndApprove('Make bed');
        $texts = $this->activityText();
        $this->assertContains('Tue: Unlocked 15 points — 1 point to Savings, 1 point to Giving, 13 points to Spending', $texts);
        $this->assertContains('Earned 10 points — Make bed (Tue)', $texts);
        $this->assertContains('Earned 5 points — Feed fish (Tue)', $texts);
    }

    public function testMoneyMovesAreWorded(): void
    {
        $this->fund('money', 1500);
        $goal = $this->parentGoal('New game', 'money', 2000);
        $this->ok($this->moveToGoal($goal['id'], 300));
        $this->ok($this->kidCall('POST', "/kid/goals/{$goal['id']}/move", ['action' => 'out', 'amount' => 100]));
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/payout", ['amount' => 500, 'note' => 'movie money']));
        $this->fund('money', 400, null, 'savings');
        $this->fund('money', 200, null, 'giving');
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/savings-transfer", ['currency' => 'money', 'amount' => 300, 'note' => 'To the bank']));
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/giving", ['currency' => 'money', 'amount' => 200, 'note' => 'Food bank']));
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 5, 'note' => 'Great week']));
        $texts = $this->activityText();
        foreach ([
            'Moved $3.00 to goal: New game',
            'Moved $1.00 back from goal: New game to Spending',
            'Paid out $5.00 — movie money',
            'Moved $3.00 from Savings to a real account — To the bank',
            'Gave $2.00 from Giving — Food bank',
            'Mom added 5 points to Spending — Great week',
        ] as $line) {
            $this->assertContains($line, $texts);
        }
    }

    public function testGoalCancelAndUsedWithLeftoverAreWorded(): void
    {
        $this->fund('points', 100);
        $a = $this->parentGoal('Game', 'points', 50);
        $this->ok($this->moveToGoal($a['id'], 30));
        $this->ok($this->kidCall('POST', "/kid/goals/{$a['id']}/cancel"));
        $b = $this->parentGoal('Toy', 'points', 40);
        $this->ok($this->moveToGoal($b['id'], 50));
        $this->ok($this->parentCall('PUT', "/goals/{$b['id']}", ['target' => 40]));
        $this->ok($this->parentCall('POST', "/goals/{$b['id']}/used"));
        $texts = $this->activityText();
        $this->assertContains('Goal cancelled: Game — 30 points back to Spending', $texts);
        $this->assertContains('Goal used: Toy — 40 points, 10 points left over went back to Spending', $texts);
    }

    public function testForfeitsAndUndoneApprovalsAreWorded(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertContains('Tue was forfeited: 5 points taken back', $this->activityText());
        $this->assertContains('Tue was forfeited: 5 points didn\'t unlock', $this->kidActivity());

        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/days/2026-12-01/unlock"));
        $this->assertContains('Tue: Forfeit reversed — 5 points available to unlock', $this->activityText());
        $id = $this->pdo->query("SELECT i.id FROM chore_instances i JOIN chores c ON c.id = i.chore_id WHERE c.title = 'Dishes' AND i.local_date = '2026-12-01'")->fetchColumn();
        $this->ok($this->parentCall('POST', "/instances/$id/undo-approval"));
        $this->assertContains('Approval undone: 5 points taken back — Dishes', $this->activityText());
    }

    public function testNewestFirstAndOnlyTheLast30Days(): void
    {
        $this->clock->set(self::DEC1);
        $this->fund('points', 10);
        $this->clock->set('2026-12-20 15:00:00');
        $this->parent = $this->ok($this->call('POST', '/login', ['email' => 'mom@example.com', 'password' => 'Correct-Horse-42']))['token'];
        $this->fund('points', 20);
        $this->clock->set('2027-01-05 15:00:00'); // Dec 1 is now 35 days ago, Dec 20 is 16 days ago
        $this->parent = $this->ok($this->call('POST', '/login', ['email' => 'mom@example.com', 'password' => 'Correct-Horse-42']))['token'];
        $this->fund('points', 30);
        $items = $this->parentActivity();
        $this->assertSame(['Mom added 30 points to Spending — test setup', 'Mom added 20 points to Spending — test setup'], array_column($items, 'text'));
        $this->assertGreaterThan($items[1]['at'], $items[0]['at']);
        $this->assertSame('2027-01-05 10:00:00', $items[0]['at_local'], 'in household time');
        $this->assertSame(60, (new Ledger($this->pdo, $this->clock))->balance($this->kidId, 'points', 'spending'), 'older moves still count in the balance');
    }

    public function testTheKidSeesOnlyTheirOwnActivityWithKidWording(): void
    {
        $this->fund('points', 10);
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $this->fund('points', 7, $sam);
        $this->assertSame(['A parent added 10 points to your Spending — test setup'], $this->kidActivity());
        $samItems = $this->ok($this->call('GET', '/kid/activity', [], $this->kidToken($samLink)))['activity'];
        $this->assertSame(['A parent added 7 points to your Spending — test setup'], array_column($samItems, 'text'));
        $this->ok($this->kidCall('GET', "/kids/{$this->kidId}/activity"), 401);
    }

    public function testOlderEntriesWithoutABatchAreStillWorded(): void
    {
        // Entries written before batches existed (Phase 2-3) have no batch or action: the wording falls back to their type.
        $hid = $this->householdId;
        $this->pdo->exec("INSERT INTO ledger_entries (household_id, kid_id, currency, bucket, entry_type, amount, local_date, created_by_type, created_at)
                          VALUES ($hid, {$this->kidId}, 'points', 'pending', 'earning', 12, '2026-12-01', 'system', '2026-12-01 14:00:00'),
                                 ($hid, {$this->kidId}, 'points', 'pending', 'unlock', -12, '2026-12-01', 'system', '2026-12-01 15:00:00'),
                                 ($hid, {$this->kidId}, 'points', 'spending', 'unlock', 12, '2026-12-01', 'system', '2026-12-01 15:00:00')");
        $this->clock->set(self::DEC2);
        $this->assertContains('Tue: Unlocked 12 points — 12 points to Spending', $this->activityText());
    }
}
