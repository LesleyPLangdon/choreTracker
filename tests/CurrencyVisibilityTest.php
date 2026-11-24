<?php
declare(strict_types=1);

namespace Tests;

/** A kid's screens show a currency only if the kid uses it (a chore, balance, goal, or bucket setting). */
final class CurrencyVisibilityTest extends MoneyTestCase
{
    private function kidToday(?string $token = null): array
    {
        return $this->kidList($token ?? $this->kid);
    }

    public function testAPointsOnlyKidNeverSeesMoney(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points']);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Make bed');
        $s = $this->kidToday()['summary'];
        $this->assertSame(['points'], $s['currencies']);
        $this->assertSame(['points' => 10], $s['spending']);
        $this->assertSame([], $s['savings'], 'no Savings row until a percentage is set or something is in it');
        $this->assertSame([], $s['giving']);
        $json = json_encode($this->summary());
        $this->assertStringNotContainsString('money', $json, 'no money rows anywhere in the kid\'s summary');
        $this->assertSame(['points'], $this->ok($this->kidCall('GET', '/kid/goals'))['currencies']);
        $this->assertArrayNotHasKey('money', $this->ok($this->kidCall('GET', '/kid/goals'))['spending']);
    }

    public function testEachWayOfUsingMoneyMakesItAppear(): void
    {
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points']);
        $this->assertSame(['points'], $this->summary()['currencies']);

        // A bucket setting in money.
        $this->setBuckets('money', 10, 0);
        $s = $this->summary();
        $this->assertSame(['points', 'money'], $s['currencies']);
        $this->assertSame(['money' => 0], $s['savings'], 'a percentage is set, so the Savings row shows');
        $this->assertSame([], $s['giving']);
        $this->setBuckets('money', 0, 0);
        $this->assertSame(['points'], $this->summary()['currencies'], 'zero removes it again');

        // A money chore.
        $id = $this->chore(['title' => 'Rake', 'value' => 500, 'currency' => 'money']);
        $this->assertSame(['points', 'money'], $this->summary()['currencies']);
        $this->ok($this->parentCall('DELETE', "/chores/$id"));
        $this->assertSame(['points'], $this->summary()['currencies']);

        // A money goal.
        $goal = $this->parentGoal('Bat', 'money', 4500);
        $this->assertSame(['points', 'money'], $this->summary()['currencies']);
        $this->ok($this->parentCall('POST', "/goals/{$goal['id']}/cancel"));
        $this->assertSame(['points'], $this->summary()['currencies'], 'a cancelled goal does not count');

        // A money balance.
        $this->fund('money', 250);
        $s = $this->summary();
        $this->assertSame(['points', 'money'], $s['currencies']);
        $this->assertSame(['points' => 0, 'money' => 250], $s['spending']);
    }

    public function testSavingsAndGivingRowsAppearOnceTheyAreInPlay(): void
    {
        $this->chore(['title' => 'Cabinets', 'value' => 1000, 'currency' => 'money']);
        $this->assertSame([], $this->summary()['savings']);
        $this->setBuckets('money', 10, 5);
        $this->clock->set(self::DEC1);
        $this->doAndApprove('Cabinets');
        $s = $this->summary();
        $this->assertSame(['money' => 100], $s['savings']);
        $this->assertSame(['money' => 50], $s['giving']);
        $this->assertSame(['money' => 850], $s['spending']);
        $this->setBuckets('money', 0, 0); // settings gone, but there is still money in the buckets
        $s = $this->summary();
        $this->assertSame(['money' => 100], $s['savings']);
    }

    public function testTheTwoKidsInOneHouseholdSeeDifferentCurrencies(): void
    {
        [$sam, $samLink] = $this->createKid($this->parent, 'Sam');
        $this->chore(['title' => 'Walk dog', 'value' => 50, 'currency' => 'money']);
        $this->createChore($this->parent, [$sam], ['title' => 'Make bed', 'value' => 10, 'currency' => 'points']);
        $alex = $this->summary();
        $samSummary = $this->ok($this->call('GET', '/kid/summary', [], $this->kidToken($samLink)))['summary'];
        $this->assertSame(['money'], $alex['currencies']);
        $this->assertSame(['points'], $samSummary['currencies']);
        $this->assertStringNotContainsString('money', json_encode($samSummary));
        $this->assertStringNotContainsString('points', json_encode($alex));
    }

    public function testTheSeededDemoKidsUseTheCurrenciesTheyShould(): void
    {
        $result = (new \App\Seeder($this->pdo, test_config(), $this->clock))->seed();
        $alex = $this->kidToken($result['kid_links']['Alex']);
        $sam = $this->kidToken($result['kid_links']['Sam']);
        $a = $this->ok($this->call('GET', '/kid/summary', [], $alex))['summary'];
        $s = $this->ok($this->call('GET', '/kid/summary', [], $sam))['summary'];
        $this->assertSame(['points', 'money'], $a['currencies']);
        $this->assertSame(['points'], $s['currencies']);
        $this->assertStringNotContainsString('money', json_encode($s));
        $this->assertSame(['money' => 0], $a['savings']);
        $this->assertSame(['money' => 0], $a['giving']);
        $this->assertCount(2, $a['goals']);
        $this->assertTrue($a['has_rewards']);
        $this->assertTrue($a['has_challenges']);
        $this->assertSame([], $s['goals']);
        $this->assertFalse($s['has_challenges']);
        $this->assertTrue($s['has_rewards']);
    }
}
