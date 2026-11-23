<?php
declare(strict_types=1);

namespace Tests;

use App\Chores\ChoreService;
use App\Chores\InstanceCreator;
use App\Chores\InstanceService;
use App\Database;
use App\Ledger;
use App\Rules\DayRules;

/** Which chores show up when: schedules, slots, one-time chores, on-demand creation, time zones. */
final class ChoreScheduleTest extends ApiTestCase
{
    private string $parent;
    private int $kidId;
    private string $kid;

    protected function setUp(): void
    {
        parent::setUp(); // clock: Tue 2026-12-01 15:00 UTC = 10:00 AM in New York
        [$this->parent] = $this->registerParent();
        [$this->kidId, $link] = $this->createKid($this->parent);
        $this->kid = $this->kidToken($link);
    }

    private function instanceService(\PDO $pdo): InstanceService
    {
        $chores = new ChoreService($pdo, $this->clock);
        $ledger = new Ledger($pdo, $this->clock);
        return new InstanceService($pdo, $this->clock, $ledger, $chores, new InstanceCreator($pdo, $this->clock, $chores), new DayRules($pdo, $this->clock, $ledger, $chores));
    }

    public function testRepeatingChoreAppearsOnlyOnScheduledDays(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Trash', 'days' => [1, 3]]); // Mon + Wed
        $this->assertSame([], $this->kidList($this->kid)['chores'], 'Tuesday: not scheduled');

        $this->clock->set('2026-12-02 15:00:00'); // Wednesday
        $this->assertSame(['Trash'], $this->titles($this->kidList($this->kid)));

        $this->clock->set('2026-12-03 15:00:00'); // Thursday
        $this->assertSame([], $this->kidList($this->kid)['chores']);

        $this->clock->set('2026-12-07 15:00:00'); // next Monday
        $this->assertSame(['Trash'], $this->titles($this->kidList($this->kid)));
    }

    public function testOneRowPerSlot(): void
    {
        $this->createChore($this->parent, [$this->kidId], [
            'title' => 'Walk the dog', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']],
        ]);
        $this->assertSame(['Walk the dog / Morning', 'Walk the dog / Evening'], $this->titles($this->kidList($this->kid)));
        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
    }

    public function testSingleSlotChoreHasNoSlotLabel(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $this->assertNull($this->kidList($this->kid)['chores'][0]['slot_label']);
    }

    public function testOneTimeChoreStaysOnTheListEveryDayAsOneInstance(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Rake', 'schedule_type' => 'one_off', 'due_date' => '2026-12-06']);
        foreach (['2026-12-01 15:00:00', '2026-12-02 15:00:00', '2026-12-03 15:00:00', '2026-12-04 15:00:00', '2026-12-06 15:00:00'] as $when) {
            $this->clock->set($when);
            $day = $this->kidList($this->kid);
            $this->assertSame(['Rake'], $this->titles($day), $when);
            $this->assertSame('2026-12-06', $day['chores'][0]['due_date']);
            $this->assertTrue($day['chores'][0]['is_one_off']);
        }
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_instances'), 'never duplicated');
        $this->assertFalse($this->kidList($this->kid)['chores'][0]['past_due_date']);

        $this->clock->set('2026-12-07 15:00:00'); // past the due date: still there, flagged
        $this->assertTrue($this->kidList($this->kid)['chores'][0]['past_due_date']);
    }

    public function testOneTimeChoreLeavesTheListAfterTheDayItIsApproved(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Rake', 'schedule_type' => 'one_off']);
        $this->clock->set('2026-12-03 15:00:00');
        $id = $this->kidList($this->kid)['chores'][0]['id'];
        $this->call('POST', "/kid/instances/$id/done", [], $this->kid);
        $this->call('POST', "/instances/$id/approve", [], $this->parent);
        $this->assertSame(['Rake'], $this->titles($this->kidList($this->kid)), 'still shown (as approved) the day it was done');

        $this->clock->set('2026-12-04 15:00:00');
        $this->assertSame([], $this->kidList($this->kid)['chores'], 'gone the next day');
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_instances'), 'and not recreated');
    }

    public function testWaitingForApprovalOneTimeChoreStaysVisibleUntilApproved(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Rake', 'schedule_type' => 'one_off']);
        $id = $this->kidList($this->kid)['chores'][0]['id'];
        $this->call('POST', "/kid/instances/$id/done", [], $this->kid);
        $this->clock->set('2026-12-02 15:00:00');
        $row = $this->kidList($this->kid)['chores'][0];
        $this->assertSame('reported_done', $row['status']);
    }

    public function testCreatingInstancesIsIdempotentEvenFromTwoConnections(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['slots' => [['label' => 'AM'], ['label' => 'PM']]]);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Rake', 'schedule_type' => 'one_off']);
        $hid = (int) $this->pdo->query('SELECT id FROM households')->fetchColumn();
        $before = $this->countRows('SELECT COUNT(*) FROM chore_instances');
        $this->assertSame(3, $before);

        // A second, independent connection and service stack (like a second request arriving at the same time).
        $other = Database::connect(test_config());
        $second = $this->instanceService($other);
        $first = $this->instanceService($this->pdo);
        for ($n = 0; $n < 3; $n++) {
            $first->ensureToday($hid, $this->kidId);
            $second->ensureToday($hid, $this->kidId);
        }
        $this->assertSame($before, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
    }

    public function testChoreAddedMidDayAppearsOnTheNextLoad(): void
    {
        $this->assertSame([], $this->kidList($this->kid)['chores']);
        $this->clock->set('2026-12-01 22:00:00'); // 5 PM
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Set the table']);
        $this->assertSame(['Set the table'], $this->titles($this->kidList($this->kid)));
    }

    public function testAssigningToSeveralKidsMakesACopyPerKidSharingAGroup(): void
    {
        [$kid2, $link2] = $this->createKid($this->parent, 'Sam');
        $ids = $this->createChore($this->parent, [$this->kidId, $kid2], ['title' => 'Dishes']);
        $this->assertCount(2, $ids);
        $this->assertSame(1, $this->countRows('SELECT COUNT(DISTINCT group_id) FROM chores'));
        $this->assertSame(['Dishes'], $this->titles($this->kidList($this->kid)));
        $this->assertSame(['Dishes'], $this->titles($this->kidList($this->kidToken($link2))));
        [, $list] = $this->call('GET', '/chores', [], $this->parent);
        $this->assertCount(1, $list['chores']);
        $this->assertCount(2, $list['chores'][0]['kids']);
    }

    public function testHouseholdTimeZoneDecidesWhichDayAChoreBelongsTo(): void
    {
        // A Wednesday-only chore. At 2026-12-01 20:00 UTC it is still Tuesday in New York but Wednesday 5 AM in Tokyo.
        $this->clock->set('2026-12-01 20:00:00');
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Wed chore', 'days' => [3]]);
        $this->assertSame([], $this->kidList($this->kid)['chores']);

        [$tokyo] = $this->registerParent('tokyo@example.com', 'Asia/Tokyo');
        [$tokyoKid, $tokyoLink] = $this->createKid($tokyo, 'Yuki');
        $this->createChore($tokyo, [$tokyoKid], ['title' => 'Wed chore', 'days' => [3]]);
        $day = $this->kidList($this->kidToken($tokyoLink));
        $this->assertSame('2026-12-02', $day['today']);
        $this->assertSame(['Wed chore'], $this->titles($day));
    }

    public function testEndOfDayTimeDecidesWhichDayAChoreBelongsTo(): void
    {
        $this->call('PUT', '/household', ['day_end_time' => '02:00'], $this->parent);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Bedtime routine']);

        $this->clock->set('2026-12-02 06:30:00'); // 1:30 AM Dec 2 in New York: still Dec 1's day
        $day = $this->kidList($this->kid);
        $this->assertSame('2026-12-01', $day['today']);
        $this->assertSame('2026-12-01', $this->pdo->query('SELECT local_date FROM chore_instances')->fetchColumn());

        $this->clock->set('2026-12-02 07:00:00'); // 2:00 AM: the new day begins
        $day = $this->kidList($this->kid);
        $this->assertSame('2026-12-02', $day['today']);
        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM chore_instances'));
    }

    public function testEveningSlotStaysOpenPastMidnightWhenTheDayEndsLater(): void
    {
        $this->call('PUT', '/household', ['day_end_time' => '02:00'], $this->parent);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Brush teeth', 'slots' => [
            ['label' => 'Morning', 'due_by' => '08:00'], ['label' => 'Evening', 'available_from' => '18:00'],
        ]]);
        $this->clock->set('2026-12-02 06:30:00'); // 1:30 AM, still "yesterday"
        $rows = $this->kidList($this->kid)['chores'];
        $morning = $rows[0];
        $evening = $rows[1];
        $this->assertSame('Evening', $evening['slot_label']);
        $this->assertTrue($evening['can_report'], 'evening is open at 1:30 AM when the day ends at 2:00 AM');
        $this->assertTrue($morning['past_due_by'], 'and the morning due-by has long passed');
    }
}
