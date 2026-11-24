<?php
declare(strict_types=1);

namespace Tests;

/** The approvals list and badge across every item type. */
final class ApprovalListTest extends MoneyTestCase
{
    public function testEveryKindOfItemIsListedAndCountedAndTheBadgeMatches(): void
    {
        $this->pdo->exec("UPDATE households SET plan = 'plus'");
        $this->chore(['title' => 'Make bed', 'value' => 10, 'currency' => 'points', 'required' => true]);
        $this->chore(['title' => 'Dishes']);
        $this->chore(['title' => 'Walk dog', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $walk = $this->group('Walk dog');
        $this->clock->set(self::DEC1);

        // a chore to approve, and (next day) a day waiting on a parent
        $bed = $this->row('Make bed')['id'];
        $this->done($bed);
        $this->clock->set(self::DEC2);
        $this->kidList($this->kid);

        // a goal request, a pending change, and a reached goal
        $requested = $this->ok($this->kidCall('POST', '/kid/goals', ['name' => 'Skates', 'currency' => 'points', 'target' => 40]), 201)['goal'];
        $reached = $this->parentGoal('Game', 'points', 20);
        $this->fund('points', 20);
        $this->ok($this->moveToGoal($reached['id'], 20));
        $changing = $this->parentGoal('Bat', 'money', 4500, 'https://a.example.com/x');
        $this->ok($this->kidCall('PUT', "/kid/goals/{$changing['id']}", ['target' => 4000]));

        // a reward request and (after approving it) a delivery
        $r1 = $this->ok($this->parentCall('POST', '/rewards', ['name' => 'Movie', 'cost' => 5, 'currency' => 'points']), 201)['reward'];
        $r2 = $this->ok($this->parentCall('POST', '/rewards', ['name' => 'Ice cream', 'cost' => 5, 'currency' => 'points']), 201)['reward'];
        $this->fund('points', 30);
        $q1 = $this->ok($this->kidCall('POST', "/kid/rewards/{$r1['id']}/request"), 201)['request'];
        $q2 = $this->ok($this->kidCall('POST', "/kid/rewards/{$r2['id']}/request"), 201)['request'];
        $this->ok($this->parentCall('POST', "/reward-requests/{$q2['id']}/approve"));

        // an earned challenge
        $cid = $this->ok($this->parentCall('POST', '/challenges', [
            'kid_id' => $this->kidId, 'reward_name' => 'Open gym', 'target_count' => 9, 'chore_group_ids' => [$walk],
            'repeat_type' => 'weekly', 'start_weekday' => 5, 'start_time' => '17:00', 'end_weekday' => 5, 'end_time' => '17:00',
        ]), 201)['challenge_id'];
        $period = (int) $this->pdo->query('SELECT id FROM challenge_periods')->fetchColumn();
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/earned"));

        $list = $this->approvals();
        $types = array_count_values(array_column($list['items'], 'type'));
        ksort($types);
        $this->assertSame([
            'challenge_earned' => 1, 'chore' => 1, 'day_waiting' => 1, 'goal_change' => 1, 'goal_reached' => 1,
            'goal_request' => 1, 'reward_delivery' => 1, 'reward_request' => 1,
        ], $types);
        $this->assertSame(7, $list['count'], 'everything that needs an action; the waiting-day summary is not counted again');
        $this->assertSame(7, $this->ok($this->parentCall('GET', '/approvals/count'))['count']);
        $this->assertSame('day_waiting', end($list['items'])['type'], 'summaries sit at the end');
        foreach ($list['items'] as $item) {
            $this->assertArrayHasKey('counted', $item);
            $this->assertSame('Alex', $item['kid']['nickname']);
        }

        // Handling each one brings the count down.
        $this->ok($this->parentCall('POST', "/goals/{$requested['id']}/approve"));
        $this->ok($this->parentCall('POST', "/goals/{$changing['id']}/deny-change"));
        $this->ok($this->parentCall('POST', "/goals/{$reached['id']}/used"));
        $this->ok($this->parentCall('POST', "/reward-requests/{$q1['id']}/deny"));
        $this->ok($this->parentCall('POST', "/reward-requests/{$q2['id']}/delivered"));
        $this->ok($this->parentCall('POST', "/challenge-periods/$period/delivered"));
        $this->assertSame(1, $this->approvals()['count']);
        $this->approve($bed);
        $left = $this->approvals();
        $this->assertSame([0, []], [$left['count'], $left['items']]);
    }

    private function group(string $title): string
    {
        $stmt = $this->pdo->prepare('SELECT group_id FROM chores WHERE title = ? ORDER BY id LIMIT 1');
        $stmt->execute([$title]);
        return (string) $stmt->fetchColumn();
    }

    public function testOnlyParentsSeeTheList(): void
    {
        $this->ok($this->kidCall('GET', '/approvals'), 401);
        $this->ok($this->kidCall('GET', '/approvals/count'), 401);
    }
}
