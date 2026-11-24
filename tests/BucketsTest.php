<?php
declare(strict_types=1);

namespace Tests;

/** Savings and Giving: the split on unlock, payouts, transfers, and adjustments in any bucket. */
final class BucketsTest extends MoneyTestCase
{
    // ---- the split on unlock -----------------------------------------------------------------------

    public function testSplitOnUnlockWithRoundingForPoints(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Feed fish', 'value' => 5, 'currency' => 'points', 'auto_approve' => true]);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Feed fish'); // auto-approved: locked until the required bed is approved
        $this->assertBuckets(['pending' => 5]);
        $this->doAndApprove('Make bed');
        // 15 points: 10% = 1.5 -> 1 to Savings, 1 to Giving; the rest (including the rounding) to Spending.
        $this->assertBuckets(['spending' => 13, 'savings' => 1, 'giving' => 1]);
        $this->assertSame(0, $this->entryCount('forfeit'));
    }

    public function testSplitOnUnlockWithRoundingForMoney(): void
    {
        $this->setBuckets('money', 10, 10);
        $this->chore(['title' => 'Cabinets', 'value' => 55, 'currency' => 'money']); // $0.55
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Cabinets'); // no required chores: goes straight through the split
        $this->assertBuckets(['spending' => 45, 'savings' => 5, 'giving' => 5], 'money'); // 5.5 cents -> 5 each
    }

    public function testRemainderGoesToSpending(): void
    {
        $this->setBuckets('points', 33, 33);
        $this->chore(['title' => 'Dishes', 'value' => 10, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertBuckets(['spending' => 4, 'savings' => 3, 'giving' => 3]); // 3.3 -> 3 and 3; 4 left over
    }

    public function testZeroPercentChangesNothing(): void
    {
        $this->chore(['title' => 'Dishes', 'value' => 7, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertBuckets(['spending' => 7]);
        $this->assertSame(0, $this->entryCount('bucket_split'));
        $this->assertSame(2, $this->entryCount('unlock'), 'pending out, spending in: same as before buckets existed');
    }

    public function testSettingsAreSeparatePerCurrencyAndPerKid(): void
    {
        $this->setBuckets('money', 20, 0);
        [$other, $otherLink] = $this->createKid($this->parent, 'Sam');
        $this->chore(['title' => 'Dishes', 'value' => 100, 'currency' => 'money']);
        $this->createChore($this->parent, [$other], ['title' => 'Dishes2', 'value' => 100, 'currency' => 'money']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertBuckets(['spending' => 80, 'savings' => 20], 'money');
        $samToken = $this->kidToken($otherLink);
        $id = $this->row('Dishes2', null, $this->kidList($samToken))['id'];
        $this->call('POST', "/kid/instances/$id/done", [], $samToken);
        $this->approve($id);
        $this->assertSame(['pending' => 0, 'spending' => 100, 'savings' => 0, 'giving' => 0, 'goal' => 0], $this->buckets('money', $other), 'Sam has no settings');
        $this->assertBuckets(['spending' => 80, 'savings' => 20], 'money', "and Alex's settings didn't change");
    }

    public function testSameDayApprovalsAfterUnlockAndNoRequiredDaysSplitToo(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Trash', 'value' => 30, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Make bed'); // unlocks: 10 -> 1/1/8
        $this->assertBuckets(['spending' => 8, 'savings' => 1, 'giving' => 1]);
        $this->doAndApprove('Trash');    // approved later the same day: 30 -> 3/3/24
        $this->assertBuckets(['spending' => 32, 'savings' => 4, 'giving' => 4]);
    }

    public function testForfeitedDaysNeverReachAnyBucket(): void
    {
        $this->setBuckets('points', 50, 50);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 20, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertBuckets([]);
    }

    public function testUnlockingAForfeitedDayLaterSplitsAndNeverMovesTwice(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 20, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->assertBuckets([]);

        // A manual unlock: the forfeit is reversed, then the 20 is split.
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/days/2026-12-01/unlock"));
        $this->assertBuckets(['spending' => 16, 'savings' => 2, 'giving' => 2]);
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/days/2026-12-01/unlock"), 409);
        $this->kidList($this->kid);
        $this->assertBuckets(['spending' => 16, 'savings' => 2, 'giving' => 2], 'points', 'nothing moved twice');
    }

    public function testBackFillingTheMissingRequiredChoreSplitsTheRecoveredDay(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes', 'value' => 20, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $slot = $this->row('Make bed')['slot_id'];
        $this->doAndApprove('Dishes');
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/back-fill", ['slot_id' => $slot, 'date' => '2026-12-01']));
        $this->assertSame('unlocked', $this->dayStatus('2026-12-01'));
        // 30 points total: 3 / 3 / 24.
        $this->assertBuckets(['spending' => 24, 'savings' => 3, 'giving' => 3]);
    }

    // ---- settings validation --------------------------------------------------------------------------

    public function testBucketSettingsValidation(): void
    {
        foreach ([[60, 50], [-1, 0], [0, 101], [101, 0]] as [$s, $g]) {
            [$status] = $this->parentCall('PUT', "/kids/{$this->kidId}/buckets", ['money' => ['savings_pct' => $s, 'giving_pct' => $g]]);
            $this->assertSame(422, $status, "$s/$g");
        }
        [$status] = $this->parentCall('PUT', "/kids/{$this->kidId}/buckets", ['money' => ['savings_pct' => 1.5, 'giving_pct' => 0]]);
        $this->assertSame(422, $status);
        $this->setBuckets('money', 60, 40); // exactly 100 is fine
        $got = $this->ok($this->parentCall('GET', "/kids/{$this->kidId}/buckets"))['buckets'];
        $this->assertSame(['savings_pct' => 60, 'giving_pct' => 40], $got['money']);
        $this->assertSame(['savings_pct' => 0, 'giving_pct' => 0], $got['points']);
        $this->setBuckets('money', 0, 0);
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM bucket_settings'), 'zero removes the setting');
    }

    // ---- Savings and Giving: marked transferred / given -------------------------------------------------

    public function testMarkTransferredAndMarkGiven(): void
    {
        $this->fund('money', 1000, null, 'savings');
        $this->fund('money', 500, null, 'giving');
        $b = $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/savings-transfer", ['currency' => 'money', 'amount' => 300, 'note' => 'To the bank']))['balances'];
        $this->assertSame(700, $b['savings']['money']);
        $b = $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/giving", ['currency' => 'money', 'amount' => 200, 'note' => 'Food bank']))['balances'];
        $this->assertSame(300, $b['giving']['money']);
        $this->assertBuckets(['savings' => 700, 'giving' => 300], 'money');
        $e = $this->pdo->query("SELECT bucket, amount, note, created_by_type FROM ledger_entries WHERE entry_type = 'payout_transfer' ORDER BY id")->fetchAll();
        $this->assertSame([['savings', -300, 'To the bank', 'parent'], ['giving', -200, 'Food bank', 'parent']],
            array_map(fn ($r) => [$r['bucket'], (int) $r['amount'], $r['note'], $r['created_by_type']], $e));

        foreach ([['savings-transfer', 701], ['giving', 301], ['savings-transfer', 0], ['giving', -5]] as [$path, $amount]) {
            [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/$path", ['currency' => 'money', 'amount' => $amount]);
            $this->assertSame(422, $status, "$path $amount");
        }
        $this->assertBuckets(['savings' => 700, 'giving' => 300], 'money');
    }

    public function testKidsCannotMoveSavingsOrGiving(): void
    {
        $this->fund('money', 1000, null, 'savings');
        foreach (['savings-transfer', 'giving', 'payout'] as $path) {
            [$status] = $this->kidCall('POST', "/kids/{$this->kidId}/$path", ['currency' => 'money', 'amount' => 100]);
            $this->assertSame(401, $status, $path);
        }
        $this->assertBuckets(['savings' => 1000], 'money');
    }

    // ---- payouts (money only) ----------------------------------------------------------------------------

    public function testPayoutIsMoneyOnlyAndCannotExceedSpending(): void
    {
        $this->fund('money', 800);
        $this->fund('points', 40);
        $b = $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/payout", ['amount' => 500, 'note' => 'movie money']))['balances'];
        $this->assertSame(300, $b['spending']['money']);
        $this->assertSame(40, $b['spending']['points'], 'points untouched');
        $this->assertSame('payout_transfer', $this->pdo->query("SELECT entry_type FROM ledger_entries WHERE note = 'movie money'")->fetchColumn());

        [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/payout", ['amount' => 301]);
        $this->assertSame(422, $status, 'more than Spending');
        [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/payout", ['currency' => 'points', 'amount' => 10]);
        $this->assertSame(200, $status, 'a "currency" field is ignored: payouts are always money');
        $this->assertBuckets(['spending' => 40], 'points', 'so points never leave through a payout');
        $this->assertBuckets(['spending' => 290], 'money');
    }

    // ---- adjustments in any bucket -----------------------------------------------------------------------

    public function testAdjustAnyBucketNeverBelowZero(): void
    {
        foreach (['spending', 'savings', 'giving'] as $bucket) {
            $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 10, 'note' => 'add', 'bucket' => $bucket]));
            [$status, $body] = $this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -11, 'note' => 'too much', 'bucket' => $bucket]);
            $this->assertSame(422, $status, $bucket);
            $this->assertStringContainsString('below zero', $body['error']);
            $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -10, 'note' => 'remove', 'bucket' => $bucket]));
        }
        $this->assertBuckets([]);
        [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 5, 'note' => 'x', 'bucket' => 'goal']);
        $this->assertSame(422, $status, 'goal money is moved by the kid, not adjusted');
        [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => 5, 'note' => 'x', 'bucket' => 'pending']);
        $this->assertSame(422, $status);
    }

    // ---- reversals on a split day ------------------------------------------------------------------------

    public function testForfeitingASplitDayTakesBackFromEveryBucket(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Dishes', 'value' => 50, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->assertBuckets(['spending' => 40, 'savings' => 5, 'giving' => 5]);
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/days/2026-12-01/forfeit"));
        $this->assertBuckets([]);
    }

    public function testForfeitIsRefusedIfSavingsWasAlreadyTransferred(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Dishes', 'value' => 50, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/savings-transfer", ['currency' => 'points', 'amount' => 5]));
        [$status] = $this->parentCall('POST', "/kids/{$this->kidId}/days/2026-12-01/forfeit");
        $this->assertSame(409, $status);
        $this->assertBuckets(['spending' => 40, 'giving' => 5], 'points', 'nothing half-moved');
    }

    public function testUndoingAnApprovalOnASplitDayTakesBackProportionally(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Dishes', 'value' => 50, 'currency' => 'points']);
        $this->chore(['title' => 'Trash', 'value' => 50, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->doAndApprove('Trash');
        $this->assertBuckets(['spending' => 80, 'savings' => 10, 'giving' => 10]);
        $id = $this->row('Dishes')['id'];
        $this->ok($this->parentCall('POST', "/instances/$id/undo-approval"));
        $this->assertBuckets(['spending' => 40, 'savings' => 5, 'giving' => 5], 'points', 'half the day came back out of each bucket');
    }

    public function testUndoIsRefusedIfSavingsHasBeenTransferredAway(): void
    {
        $this->setBuckets('points', 10, 10);
        $this->chore(['title' => 'Dishes', 'value' => 50, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Dishes');
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/savings-transfer", ['currency' => 'points', 'amount' => 5]));
        [$status] = $this->parentCall('POST', '/instances/' . $this->row('Dishes')['id'] . '/undo-approval');
        $this->assertSame(409, $status);
        $this->assertBuckets(['spending' => 40, 'giving' => 5]);
    }
}
