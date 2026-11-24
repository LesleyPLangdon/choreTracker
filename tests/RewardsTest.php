<?php
declare(strict_types=1);

namespace Tests;

/** Rewards: the parent's list, the kid's requests, approval from Spending, and "Save for this". */
final class RewardsTest extends MoneyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set(self::DEC1);
    }

    private function reward(string $name = 'Pick the movie', int $cost = 50, string $currency = 'points', array $kidIds = []): array
    {
        return $this->ok($this->parentCall('POST', '/rewards', ['name' => $name, 'cost' => $cost, 'currency' => $currency, 'kid_ids' => $kidIds]), 201)['reward'];
    }

    private function kidRewards(?string $token = null): array
    {
        [$status, $body] = $this->call('GET', '/kid/rewards', [], $token ?? $this->kid);
        $this->assertSame(200, $status);
        return array_column($body['rewards'], null, 'name');
    }

    private function requestReward(int $id): array
    {
        return $this->kidCall('POST', "/kid/rewards/$id/request");
    }

    // ---- the parent's list ---------------------------------------------------------------------------

    public function testParentCreatesEditsAndRemovesRewards(): void
    {
        $r = $this->reward('Ice cream trip', 200);
        $this->assertSame(['Ice cream trip', 200, 'points', []], [$r['name'], $r['cost'], $r['currency'], $r['kid_ids']]);
        $e = $this->ok($this->parentCall('PUT', "/rewards/{$r['id']}", ['name' => 'Ice cream!', 'cost' => 150, 'currency' => 'points']))['reward'];
        $this->assertSame(['Ice cream!', 150], [$e['name'], $e['cost']]);
        $this->reward('Movie money', 500, 'money');
        $names = array_column($this->ok($this->parentCall('GET', '/rewards'))['rewards'], 'name');
        $this->assertEqualsCanonicalizing(['Ice cream!', 'Movie money'], $names);
        $this->ok($this->parentCall('DELETE', "/rewards/{$r['id']}"));
        $this->assertSame(['Movie money'], array_column($this->ok($this->parentCall('GET', '/rewards'))['rewards'], 'name'));
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM rewards WHERE active = 0'), 'removed means hidden, not deleted');
        $this->assertArrayNotHasKey('Ice cream!', $this->kidRewards());
    }

    public function testRewardValidation(): void
    {
        foreach ([['name' => '', 'cost' => 5], ['name' => 'X', 'cost' => 0], ['name' => 'X', 'cost' => 1.5], ['name' => 'X', 'cost' => 5, 'currency' => 'gems'],
                  ['name' => 'X', 'cost' => 5, 'kid_ids' => [9999]]] as $bad) {
            $this->ok($this->parentCall('POST', '/rewards', $bad), 422);
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM rewards'));
    }

    // ---- who can see a reward ------------------------------------------------------------------------

    public function testRewardsCanBeLimitedToCertainKids(): void
    {
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $samToken = $this->kidToken($samLink);
        $this->reward('For everyone', 50);
        $this->reward('Only Alex', 60, 'points', [$this->kidId]);
        $this->reward('Only Sam', 70, 'points', [$sam]);

        $this->assertEqualsCanonicalizing(['For everyone', 'Only Alex'], array_keys($this->kidRewards()));
        $this->assertEqualsCanonicalizing(['For everyone', 'Only Sam'], array_keys($this->kidRewards($samToken)));
        $onlySam = $this->kidRewards($samToken)['Only Sam'];
        $this->fund('points', 500);
        $this->ok($this->requestReward($onlySam['id']), 404); // Alex can't ask for Sam's reward
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM reward_requests'));
    }

    // ---- request, approve, deliver ----------------------------------------------------------------------

    public function testRequestOnlyIfSpendingCoversThePrice(): void
    {
        $r = $this->reward('Pick the movie', 50);
        $row = $this->kidRewards()['Pick the movie'];
        $this->assertFalse($row['affordable']);
        $this->assertTrue($row['can_save'], 'offers "Save for this"');
        [$status, $body] = $this->requestReward($r['id']);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('Save for this', $body['error']);
        $this->fund('points', 49);
        $this->assertSame(422, $this->requestReward($r['id'])[0]);
        $this->fund('points', 1);
        $this->assertTrue($this->kidRewards()['Pick the movie']['affordable']);
        $this->ok($this->requestReward($r['id']), 201);
        $this->assertBuckets(['spending' => 50], 'points', 'nothing is taken at request time');
    }

    public function testApprovalTakesThePriceFromSpendingThenDelivered(): void
    {
        $r = $this->reward('Pick the movie', 50);
        $this->fund('points', 80);
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        $items = $this->approvals();
        $this->assertSame(['reward_request'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);

        $approved = $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve", ['note' => 'Enjoy!']))['request'];
        $this->assertSame('approved', $approved['status']);
        $this->assertBuckets(['spending' => 30]);
        $e = $this->pdo->query("SELECT bucket, amount, reward_request_id, created_by_type FROM ledger_entries WHERE entry_type = 'reward_redemption'")->fetchAll();
        $this->assertSame([['spending', -50, $req['id'], 'parent']], array_map(fn ($x) => [$x['bucket'], (int) $x['amount'], (int) $x['reward_request_id'], $x['created_by_type']], $e));
        // Approved but not handed over yet: still on the parent's list, as a delivery.
        $items = $this->approvals();
        $this->assertSame(['reward_delivery'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);

        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/delivered"));
        $this->assertSame(0, $this->approvals()['count']);
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"), 409);
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/delivered"), 409);
        $this->assertBuckets(['spending' => 30]);
        $this->assertContains('Reward: Pick the movie — 50 points', $this->activityText());
    }

    public function testApprovalIsBlockedIfTheBalanceNoLongerCoversIt(): void
    {
        $r = $this->reward('Pick the movie', 50);
        $this->fund('points', 60);
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/adjust", ['currency' => 'points', 'amount' => -30, 'note' => 'spent elsewhere']));
        [$status, $body] = $this->parentCall('POST', "/reward-requests/{$req['id']}/approve");
        $this->assertSame(409, $status);
        $this->assertStringContainsString('no longer covers', $body['error']);
        $this->assertBuckets(['spending' => 30]);
        $this->assertSame('requested', $this->kidRewardsRequestStatus($req['id']), 'still open: the parent can adjust or deny');
        $this->fund('points', 20);
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"));
        $this->assertBuckets(['spending' => 0]);
    }

    private function kidRewardsRequestStatus(int $id): string
    {
        return (string) $this->pdo->query("SELECT status FROM reward_requests WHERE id = $id")->fetchColumn();
    }

    public function testDenyTakesNothingAndKidCanCancelBeforeApproval(): void
    {
        $r = $this->reward('Pick the movie', 50);
        $this->fund('points', 100);
        $a = $this->ok($this->requestReward($r['id']), 201)['request'];
        $denied = $this->ok($this->parentCall('POST', "/reward-requests/{$a['id']}/deny", ['note' => 'Not on a school night']))['request'];
        $this->assertSame(['denied', 'Not on a school night'], [$denied['status'], $denied['decision_note']]);
        $this->assertBuckets(['spending' => 100]);
        $this->ok($this->parentCall('POST', "/reward-requests/{$a['id']}/deny"), 409);

        $b = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->ok($this->requestReward($r['id']), 409); // already asked
        $this->ok($this->kidCall('POST', "/kid/reward-requests/{$b['id']}/cancel"));
        $this->assertSame('cancelled', $this->kidRewardsRequestStatus($b['id']));
        $this->assertSame(0, $this->approvals()['count']);
        $this->ok($this->kidCall('POST', "/kid/reward-requests/{$b['id']}/cancel"), 409);

        $c = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->ok($this->parentCall('POST', "/reward-requests/{$c['id']}/approve"));
        $this->ok($this->kidCall('POST', "/kid/reward-requests/{$c['id']}/cancel"), 409); // too late
        $this->assertBuckets(['spending' => 50]);
    }

    public function testMoneyPricedRewardsWorkTheSameWay(): void
    {
        $r = $this->reward('Movie money', 500, 'money');
        $this->fund('money', 700);
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"));
        $this->assertBuckets(['spending' => 200], 'money');
    }

    public function testAnotherKidOrHouseholdCannotDecideOrCancelARequest(): void
    {
        $r = $this->reward('Pick the movie', 50);
        $this->fund('points', 100);
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $this->ok($this->call('POST', "/kid/reward-requests/{$req['id']}/cancel", [], $this->kidToken($samLink)), 404);
        [$other] = $this->registerParent('other@example.com');
        foreach (['approve', 'deny', 'delivered'] as $action) {
            $this->ok($this->call('POST', "/reward-requests/{$req['id']}/$action", [], $other), 404);
        }
        $this->ok($this->kidCall('POST', "/reward-requests/{$req['id']}/approve"), 401);
        $this->assertSame('requested', $this->kidRewardsRequestStatus($req['id']));
    }

    // ---- Save for this ------------------------------------------------------------------------------------

    public function testSaveForThisCreatesALinkedApprovedGoal(): void
    {
        $r = $this->reward('Stay up late', 100);
        $row = $this->kidRewards()['Stay up late'];
        $this->assertTrue($row['can_save']);
        $g = $this->ok($this->kidCall('POST', "/kid/rewards/{$r['id']}/save"), 201)['goal'];
        $this->assertSame(['Stay up late', 'points', 100, 'active', $r['id']], [$g['name'], $g['currency'], $g['target'], $g['status'], $g['reward_id']]);
        $this->assertSame(0, $this->approvals()['count'], 'already approved: the parent made the reward');
        $this->ok($this->kidCall('POST', "/kid/rewards/{$r['id']}/save"), 409);
        $after = $this->kidRewards()['Stay up late'];
        $this->assertFalse($after['can_save']);
        $this->assertSame($g['id'], $after['goal']['id']);
    }

    public function testSaveForThisIsLimitedByThePlan(): void
    {
        $this->parentGoal('Something else', 'points', 10);
        $r = $this->reward('Stay up late', 100);
        [$status, $body] = $this->kidCall('POST', "/kid/rewards/{$r['id']}/save");
        $this->assertSame(409, $status);
        $this->assertStringContainsString('one goal at a time', $body['error']);
    }

    public function testRequestingAReachedRewardGoalPaysFromTheGoalFirst(): void
    {
        $r = $this->reward('Stay up late', 50);
        $goal = $this->ok($this->kidCall('POST', "/kid/rewards/{$r['id']}/save"), 201)['goal'];
        $this->fund('points', 50);
        $this->ok($this->moveToGoal($goal['id'], 50));
        $this->assertSame('reached', $this->ok($this->kidCall('GET', '/kid/goals'))['goals'][0]['status']);
        $this->assertBuckets(['spending' => 0, 'goal' => 50]);

        $row = $this->kidRewards()['Stay up late'];
        $this->assertTrue($row['affordable'], 'the reached goal counts toward the price');
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"));
        $this->assertBuckets(['spending' => 0, 'goal' => 0], 'points', 'paid entirely from the goal');
        $this->assertSame('used', $this->pdo->query("SELECT status FROM goals WHERE id = {$goal['id']}")->fetchColumn());
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM ledger_entries WHERE bucket = 'goal' AND entry_type = 'reward_redemption'"));
        $this->assertSame(0, $this->approvals()['count'] - 1, 'the delivery is what is left');
    }

    public function testTheGoalPaysFirstAndSpendingCoversTheRest(): void
    {
        $r = $this->reward('Stay up late', 50);
        $goal = $this->ok($this->kidCall('POST', "/kid/rewards/{$r['id']}/save"), 201)['goal'];
        $this->fund('points', 50);
        $this->ok($this->moveToGoal($goal['id'], 50));
        $this->ok($this->parentCall('PUT', "/rewards/{$r['id']}", ['name' => 'Stay up late', 'cost' => 70, 'currency' => 'points'])); // the price went up
        $this->assertFalse($this->kidRewards()['Stay up late']['affordable'], '50 from the goal, 0 in Spending: 20 short');
        $this->ok($this->requestReward($r['id']), 422);

        $this->fund('points', 25);
        $req = $this->ok($this->requestReward($r['id']), 201)['request'];
        $this->assertSame(70, $req['cost']);
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"));
        $this->assertBuckets(['spending' => 5, 'goal' => 0], 'points', '50 from the goal + 20 from Spending');
    }

    public function testAGoalThatIsNotReachedDoesNotPayAndKeepsItsMoney(): void
    {
        $r = $this->reward('Stay up late', 50);
        $goal = $this->ok($this->kidCall('POST', "/kid/rewards/{$r['id']}/save"), 201)['goal'];
        $this->fund('points', 130);
        $this->ok($this->moveToGoal($goal['id'], 30)); // not reached
        $req = $this->ok($this->requestReward($r['id']), 201)['request']; // affordable from Spending (100)
        $this->ok($this->parentCall('POST', "/reward-requests/{$req['id']}/approve"));
        $this->assertBuckets(['spending' => 50, 'goal' => 30]);
        $this->assertSame('active', $this->pdo->query("SELECT status FROM goals WHERE id = {$goal['id']}")->fetchColumn());
    }
}
