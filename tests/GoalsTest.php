<?php
declare(strict_types=1);

namespace Tests;

/** Goals: requests, approvals, links, moving money, reached, used, cancelled. */
final class GoalsTest extends MoneyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set(self::DEC1);
    }

    private function plus(): void
    {
        $this->pdo->exec("UPDATE households SET plan = 'plus'");
    }

    private function request(string $name = 'New game', string $currency = 'money', int $target = 2000, array $extra = []): array
    {
        return $this->ok($this->kidCall('POST', '/kid/goals', ['name' => $name, 'currency' => $currency, 'target' => $target] + $extra), 201)['goal'];
    }

    private function approveGoal(int $id, array $body = []): array
    {
        return $this->ok($this->parentCall('POST', "/goals/$id/approve", $body))['goal'];
    }

    private function goal(int $id): array
    {
        foreach ($this->ok($this->kidCall('GET', '/kid/goals'))['goals'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        $this->fail("goal $id not in the kid's list");
    }

    // ---- request, approve, deny -----------------------------------------------------------------

    public function testKidRequestsAndParentApprovesOrDenies(): void
    {
        $this->plus();
        $g = $this->request();
        $this->assertSame('requested', $g['status']);
        $this->assertSame(0, $g['balance']);
        $items = $this->approvals();
        $this->assertSame(['goal_request'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);

        $this->approveGoal($g['id'], ['note' => 'Nice pick']);
        $this->assertSame('active', $this->goal($g['id'])['status']);
        $this->assertSame(0, $this->approvals()['count']);

        $second = $this->request('Skateboard', 'money', 6000);
        $this->ok($this->parentCall('POST', "/goals/{$second['id']}/deny", ['note' => 'Too expensive for now']));
        $denied = $this->goal($second['id']);
        $this->assertSame('denied', $denied['status']);
        $this->assertSame('Too expensive for now', $denied['decision_note']);
        $this->ok($this->parentCall('POST', "/goals/{$second['id']}/approve"), 409);
    }

    public function testParentCanCreateAnApprovedGoalDirectly(): void
    {
        $g = $this->parentGoal('Baseball bat', 'money', 4500, 'https://www.dickssportinggoods.com/p/bat');
        $this->assertSame('active', $g['status']);
        $this->assertSame('dickssportinggoods.com', $g['link_host']);
        $this->assertSame(0, $this->approvals()['count']);
    }

    public function testGoalValidation(): void
    {
        foreach ([['name' => '', 'currency' => 'money', 'target' => 100], ['name' => 'X', 'currency' => 'gems', 'target' => 100],
                  ['name' => 'X', 'currency' => 'money', 'target' => 0], ['name' => 'X', 'currency' => 'money', 'target' => 1.5],
                  ['name' => 'X', 'currency' => 'money'], ['name' => str_repeat('a', 101), 'currency' => 'money', 'target' => 5]] as $bad) {
            $this->ok($this->kidCall('POST', '/kid/goals', $bad), 422);
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM goals'));
    }

    // ---- links ---------------------------------------------------------------------------------------

    public function testOnlyHttpAndHttpsLinksAreAcceptedAndShownAsTheSiteName(): void
    {
        $this->plus();
        foreach (['javascript:alert(1)', 'ftp://example.com/x', 'data:text/html,hi', 'example.com/bat', '//example.com', 'https://', 'https://exa mple.com',
                  'https://example.com/' . str_repeat('a', 500), "https://example.com/\nx", 'file:///etc/passwd', 'https:///nohost'] as $bad) {
            $this->ok($this->kidCall('POST', '/kid/goals', ['name' => 'X', 'currency' => 'money', 'target' => 100, 'link' => $bad]), 422);
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM goals'));

        $g = $this->request('Bat', 'money', 4500, ['link' => 'https://www.DicksSportingGoods.com/p/bat?id=7']);
        $this->assertSame('https://www.DicksSportingGoods.com/p/bat?id=7', $g['link'], 'stored as plain text, exactly as given');
        $this->assertSame('dickssportinggoods.com', $g['link_host'], 'shown as the site name');
        $h = $this->request('Game', 'points', 50, ['link' => 'http://shop.example.org/game']);
        $this->assertSame('shop.example.org', $h['link_host']);
        $this->assertNull($this->request('NoLink', 'points', 5, ['link' => '  '])['link']);
    }

    public function testParentCanEditOrRemoveTheLinkBeforeApprovingAndAnyTimeAfter(): void
    {
        $g = $this->request('Bat', 'money', 4500, ['link' => 'https://a.example.com/x']);
        $approved = $this->approveGoal($g['id'], ['link' => 'https://b.example.com/y']);
        $this->assertSame('b.example.com', $approved['link_host']);

        $edited = $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['link' => 'https://c.example.com/z', 'target' => 5000, 'name' => 'Better bat']))['goal'];
        $this->assertSame(['Better bat', 5000, 'c.example.com'], [$edited['name'], $edited['target'], $edited['link_host']]);
        $removed = $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['link' => null]))['goal'];
        $this->assertNull($removed['link']);
        $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['link' => 'javascript:x']), 422);
        $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['currency' => 'points']), 422);
    }

    public function testAKidsLinkOrPriceChangeOnAnApprovedGoalWaitsForAParent(): void
    {
        $g = $this->parentGoal('Bat', 'money', 4500, 'https://a.example.com/x');
        // Name: immediate.
        $this->assertSame('Big bat', $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['name' => 'Big bat']))['goal']['name']);
        // Link + price: held for approval; the goal stays active with its old values.
        $changed = $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['link' => 'https://b.example.com/new', 'target' => 3000]))['goal'];
        $this->assertSame('active', $changed['status']);
        $this->assertSame(['a.example.com', 4500], [$changed['link_host'], $changed['target']]);
        $this->assertSame(['link_changed' => true, 'link' => 'https://b.example.com/new', 'link_host' => 'b.example.com', 'target' => 3000], $changed['pending_change']);
        $items = $this->approvals();
        $this->assertSame(['goal_change'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);

        $applied = $this->ok($this->parentCall('POST', "/goals/{$g['id']}/approve-change"))['goal'];
        $this->assertSame(['b.example.com', 3000], [$applied['link_host'], $applied['target']]);
        $this->assertNull($applied['pending_change']);
        $this->assertSame(0, $this->approvals()['count']);

        // A denied change leaves the goal as it was.
        $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['link' => null]));
        $this->assertTrue($this->goal($g['id'])['pending_change']['link_changed']);
        $denied = $this->ok($this->parentCall('POST', "/goals/{$g['id']}/deny-change"))['goal'];
        $this->assertSame('b.example.com', $denied['link_host']);
        $this->assertNull($denied['pending_change']);
        $this->ok($this->parentCall('POST', "/goals/{$g['id']}/deny-change"), 409);

        // Changing it back to what it was cancels the request.
        $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['target' => 100]));
        $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['target' => 3000]));
        $this->assertNull($this->goal($g['id'])['pending_change']);
    }

    public function testAKidEditsARequestedGoalDirectly(): void
    {
        $g = $this->request('Bat', 'money', 4500, ['link' => 'https://a.example.com/x']);
        $e = $this->ok($this->kidCall('PUT', "/kid/goals/{$g['id']}", ['target' => 4000, 'link' => 'https://b.example.com/y']))['goal'];
        $this->assertSame([4000, 'b.example.com', null], [$e['target'], $e['link_host'], $e['pending_change']], 'still just a request, so the parent sees the latest');
    }

    // ---- the free plan -----------------------------------------------------------------------------

    public function testTheFreePlanAllowsOneGoalAtATimeWithAFriendlyMessage(): void
    {
        $first = $this->request('First', 'points', 50);
        [$status, $body] = $this->kidCall('POST', '/kid/goals', ['name' => 'Second', 'currency' => 'points', 'target' => 60]);
        $this->assertSame(409, $status);
        $this->assertStringContainsString('one goal at a time', $body['error']);
        $this->assertFalse($this->ok($this->kidCall('GET', '/kid/goals'))['can_add']);
        // A parent can't add a second one either...
        $this->ok($this->parentCall('POST', "/kids/{$this->kidId}/goals", ['name' => 'Second', 'currency' => 'points', 'target' => 60]), 409);
        // ...until the first is cancelled.
        $this->ok($this->kidCall('POST', "/kid/goals/{$first['id']}/cancel"));
        $this->assertTrue($this->ok($this->kidCall('GET', '/kid/goals'))['can_add']);
        $this->request('Second', 'points', 60);
    }

    public function testAPlanThatAllowsSeveralGoals(): void
    {
        $this->plus();
        $this->request('One', 'points', 10);
        $this->request('Two', 'points', 20);
        $this->request('Three', 'money', 300);
        $this->assertSame(3, $this->countRows("SELECT COUNT(*) FROM goals WHERE status = 'requested'"));
    }

    // ---- moving money -----------------------------------------------------------------------------

    public function testMoveMoneyInAndBackOut(): void
    {
        $this->fund('points', 100);
        $g = $this->parentGoal('Game', 'points', 80);
        $this->ok($this->moveToGoal($g['id'], 30));
        $this->assertBuckets(['spending' => 70, 'goal' => 30]);
        $this->assertSame(30, $this->goal($g['id'])['balance']);
        $this->ok($this->kidCall('POST', "/kid/goals/{$g['id']}/move", ['action' => 'out', 'amount' => 10]));
        $this->assertBuckets(['spending' => 80, 'goal' => 20]);

        [$status] = $this->moveToGoal($g['id'], 81);
        $this->assertSame(422, $status, 'more than Spending');
        [$status] = $this->kidCall('POST', "/kid/goals/{$g['id']}/move", ['action' => 'out', 'amount' => 21]);
        $this->assertSame(422, $status, 'more than the goal holds');
        foreach ([0, -5] as $bad) {
            $this->assertSame(422, $this->moveToGoal($g['id'], $bad)[0]);
        }
        $this->assertBuckets(['spending' => 80, 'goal' => 20]);
        $this->assertSame(422, $this->kidCall('POST', "/kid/goals/{$g['id']}/move", ['action' => 'sideways', 'amount' => 5])[0]);
    }

    public function testAGoalNeedsApprovalBeforeItCanBeFunded(): void
    {
        $this->fund('points', 100);
        $g = $this->request('Game', 'points', 80);
        [$status, $body] = $this->moveToGoal($g['id'], 10);
        $this->assertSame(409, $status);
        $this->assertStringContainsString('approve', $body['error']);
        $this->assertBuckets(['spending' => 100]);
    }

    public function testMoveMoneyBetweenGoalsSameCurrencyOnly(): void
    {
        $this->plus();
        $this->fund('points', 100);
        $this->fund('money', 1000);
        $a = $this->parentGoal('A', 'points', 100);
        $b = $this->parentGoal('B', 'points', 50);
        $m = $this->parentGoal('M', 'money', 500);
        $this->ok($this->moveToGoal($a['id'], 60));
        $this->ok($this->moveToGoal($m['id'], 200));

        $this->ok($this->kidCall('POST', "/kid/goals/{$a['id']}/move", ['action' => 'transfer', 'amount' => 25, 'to_goal_id' => $b['id']]));
        $this->assertSame([35, 25], [$this->goal($a['id'])['balance'], $this->goal($b['id'])['balance']]);
        $this->assertBuckets(['spending' => 40, 'goal' => 60]);

        [$status, $body] = $this->kidCall('POST', "/kid/goals/{$a['id']}/move", ['action' => 'transfer', 'amount' => 5, 'to_goal_id' => $m['id']]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('same currency', $body['error']);
        $this->ok($this->kidCall('POST', "/kid/goals/{$a['id']}/move", ['action' => 'transfer', 'amount' => 36, 'to_goal_id' => $b['id']]), 422); // more than it holds
        $this->ok($this->kidCall('POST', "/kid/goals/{$a['id']}/move", ['action' => 'transfer', 'amount' => 5, 'to_goal_id' => $a['id']]), 422); // itself
        $this->ok($this->kidCall('POST', "/kid/goals/{$a['id']}/move", ['action' => 'transfer', 'amount' => 5]), 422);                            // no target
        $this->assertSame([35, 25], [$this->goal($a['id'])['balance'], $this->goal($b['id'])['balance']]);
        $this->assertBuckets(['spending' => 800, 'goal' => 200], 'money', 'the money goal was untouched');
        $this->assertContains('Moved 25 points from goal A to goal B', $this->activityText());
    }

    // ---- reached ----------------------------------------------------------------------------------------

    public function testReachedGoalGoesToApprovalsAndFallsBackIfMoneyMovesOut(): void
    {
        $this->fund('points', 100);
        $g = $this->parentGoal('Game', 'points', 60);
        $this->ok($this->moveToGoal($g['id'], 59));
        $this->assertSame('active', $this->goal($g['id'])['status']);
        $this->ok($this->moveToGoal($g['id'], 1));
        $this->assertSame('reached', $this->goal($g['id'])['status']);
        $items = $this->approvals();
        $this->assertSame(['goal_reached'], array_column($items['items'], 'type'));
        $this->assertSame(1, $items['count']);
        $this->assertSame('Alex', $items['items'][0]['kid']['nickname']);

        $this->ok($this->kidCall('POST', "/kid/goals/{$g['id']}/move", ['action' => 'out', 'amount' => 5]));
        $this->assertSame('active', $this->goal($g['id'])['status']);
        $this->assertSame(0, $this->approvals()['count']);

        // Raising the price un-reaches it; lowering it reaches it.
        $this->ok($this->moveToGoal($g['id'], 5));
        $this->assertSame('reached', $this->goal($g['id'])['status']);
        $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['target' => 100]));
        $this->assertSame('active', $this->goal($g['id'])['status']);
        $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['target' => 40]));
        $this->assertSame('reached', $this->goal($g['id'])['status']);
    }

    // ---- used, cancelled --------------------------------------------------------------------------------

    public function testMarkUsedTakesTheGoalMoneyOut(): void
    {
        $this->fund('money', 5000);
        $g = $this->parentGoal('Bat', 'money', 4500);
        $this->ok($this->moveToGoal($g['id'], 4500));
        $used = $this->ok($this->parentCall('POST', "/goals/{$g['id']}/used"))['goal'];
        $this->assertSame(['used', 0], [$used['status'], $used['balance']]);
        $this->assertBuckets(['spending' => 500], 'money', 'the $45 left the system (it is the payout for the item)');
        $this->assertSame(0, $this->approvals()['count']);
        $this->ok($this->parentCall('POST', "/goals/{$g['id']}/used"), 409);
        $this->assertContains('Goal used: Bat — $45.00', $this->activityText());
    }

    public function testLeftoverBeyondThePriceGoesBackToSpending(): void
    {
        $this->fund('points', 100);
        $g = $this->parentGoal('Game', 'points', 60);
        $this->ok($this->moveToGoal($g['id'], 60));
        $this->fund('points', 0 + 1); // unrelated
        $this->ok($this->parentCall('PUT', "/goals/{$g['id']}", ['target' => 50])); // price dropped after saving 60
        $this->ok($this->parentCall('POST', "/goals/{$g['id']}/used"));
        $this->assertBuckets(['spending' => 51]); // 100 - 60 + 1 + 10 left over
    }

    public function testUsedBeforeFullyFundedUsesWhatTheKidHas(): void
    {
        $this->fund('money', 1000);
        $g = $this->parentGoal('Bat', 'money', 4500);
        $this->ok($this->moveToGoal($g['id'], 700));
        $used = $this->ok($this->parentCall('POST', "/goals/{$g['id']}/used"))['goal'];
        $this->assertSame('used', $used['status']);
        $this->assertBuckets(['spending' => 300], 'money', "the kid's \$7 is used; the parent covers the rest in real life");
    }

    public function testCancelReturnsTheBalanceToSpending(): void
    {
        $this->fund('points', 100);
        $g = $this->parentGoal('Game', 'points', 80);
        $this->ok($this->moveToGoal($g['id'], 45));
        $this->ok($this->kidCall('POST', "/kid/goals/{$g['id']}/cancel"));
        $this->assertBuckets(['spending' => 100]);
        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM goals WHERE status IN ('active', 'reached')"));
        $this->ok($this->kidCall('POST', "/kid/goals/{$g['id']}/cancel"), 409);

        $h = $this->parentGoal('Other', 'points', 30);
        $this->ok($this->moveToGoal($h['id'], 30));
        $this->ok($this->parentCall('POST', "/goals/{$h['id']}/cancel"));
        $this->assertBuckets(['spending' => 100]);
    }

    // ---- who can do what ------------------------------------------------------------------------------

    public function testKidsAndOtherHouseholdsCannotTouchEachOthersGoals(): void
    {
        $this->plus();
        $this->fund('points', 100);
        $mine = $this->parentGoal('Mine', 'points', 50);
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $samToken = $this->kidToken($samLink);
        foreach ([['POST', "/kid/goals/{$mine['id']}/move", ['action' => 'in', 'amount' => 5]], ['POST', "/kid/goals/{$mine['id']}/cancel", []], ['PUT', "/kid/goals/{$mine['id']}", ['name' => 'x']]] as [$m, $p, $b]) {
            $this->ok($this->call($m, $p, $b, $samToken), 404);
        }
        [$other] = $this->registerParent('other@example.com');
        foreach ([['POST', "/goals/{$mine['id']}/used"], ['POST', "/goals/{$mine['id']}/cancel"], ['PUT', "/goals/{$mine['id']}"], ['POST', "/goals/{$mine['id']}/approve"], ['GET', "/kids/{$this->kidId}/goals"]] as [$m, $p]) {
            $this->ok($this->call($m, $p, [], $other), 404);
        }
        foreach ([['POST', "/goals/{$mine['id']}/used"], ['POST', "/goals/{$mine['id']}/approve"], ['POST', "/kids/{$this->kidId}/goals"], ['GET', '/rewards']] as [$m, $p]) {
            $this->ok($this->kidCall($m, $p), 401);
        }
        $this->assertSame('active', $this->goal($mine['id'])['status']);
    }
}
