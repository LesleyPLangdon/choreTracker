<?php
declare(strict_types=1);

namespace Tests;

/** Kids only act on their own chores; parents stay inside their household. */
final class ChorePermissionsTest extends ApiTestCase
{
    private string $parentA;
    private string $parentB;
    private int $alexId;
    private int $samId;
    private string $alex;
    private string $sam;
    private int $alexInstance;
    private int $samInstance;
    private int $choreId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->parentA] = $this->registerParent('a@example.com');
        [$this->parentB] = $this->registerParent('b@example.com');
        [$this->alexId, $alexLink] = $this->createKid($this->parentA, 'Alex');
        [$this->samId, $samLink] = $this->createKid($this->parentA, 'Sam');
        $this->alex = $this->kidToken($alexLink);
        $this->sam = $this->kidToken($samLink);
        $ids = $this->createChore($this->parentA, [$this->alexId, $this->samId], ['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $this->choreId = $ids[0];
        $this->alexInstance = $this->kidList($this->alex)['chores'][0]['id'];
        $this->samInstance = $this->kidList($this->sam)['chores'][0]['id'];
    }

    public function testEachKidSeesOnlyTheirOwnChores(): void
    {
        $this->assertSame([$this->alexInstance], array_column($this->kidList($this->alex)['chores'], 'id'));
        $this->assertSame([$this->samInstance], array_column($this->kidList($this->sam)['chores'], 'id'));
        $this->assertSame('Alex', $this->kidList($this->alex)['kid']['nickname']);
    }

    public function testKidCannotActOnAnotherKidsChore(): void
    {
        foreach (['done', 'undo'] as $action) {
            [$status] = $this->call('POST', "/kid/instances/{$this->samInstance}/$action", [], $this->alex);
            $this->assertSame(404, $status, $action);
        }
        $this->assertSame('assigned', $this->pdo->query("SELECT status FROM chore_instances WHERE id = {$this->samInstance}")->fetchColumn());

        // Even after Sam reports, Alex can't undo Sam's report.
        $this->call('POST', "/kid/instances/{$this->samInstance}/done", [], $this->sam);
        [$status] = $this->call('POST', "/kid/instances/{$this->samInstance}/undo", [], $this->alex);
        $this->assertSame(404, $status);
        $this->assertSame('reported_done', $this->pdo->query("SELECT status FROM chore_instances WHERE id = {$this->samInstance}")->fetchColumn());
    }

    public function testKidsCannotUseParentEndpointsAndParentsCannotUseKidEndpoints(): void
    {
        $this->call('POST', "/kid/instances/{$this->alexInstance}/done", [], $this->alex);
        foreach ([
            ['POST', "/instances/{$this->alexInstance}/approve"], ['POST', "/instances/{$this->alexInstance}/send-back"],
            ['POST', "/instances/{$this->alexInstance}/mark-done"], ['GET', '/approvals'], ['GET', '/approvals/count'],
            ['GET', '/chores'], ['POST', '/chores'], ['GET', "/kids/{$this->samId}/today"],
        ] as [$method, $path]) {
            [$status] = $this->call($method, $path, [], $this->alex);
            $this->assertSame(401, $status, "$method $path");
        }
        foreach ([['GET', '/kid/today'], ['POST', "/kid/instances/{$this->alexInstance}/done"], ['POST', "/kid/instances/{$this->alexInstance}/undo"]] as [$method, $path]) {
            [$status] = $this->call($method, $path, [], $this->parentA);
            $this->assertSame(401, $status, "$method $path (parent token)");
        }
        $this->assertSame('reported_done', $this->pdo->query("SELECT status FROM chore_instances WHERE id = {$this->alexInstance}")->fetchColumn());
    }

    public function testNoToken(): void
    {
        foreach ([['GET', '/kid/today'], ['GET', '/approvals'], ['GET', '/chores'], ['POST', "/kid/instances/{$this->alexInstance}/done"]] as [$method, $path]) {
            [$status] = $this->call($method, $path);
            $this->assertSame(401, $status, "$method $path");
        }
    }

    public function testAParentCannotReachAnotherHouseholdsChoresOrKids(): void
    {
        $this->call('POST', "/kid/instances/{$this->alexInstance}/done", [], $this->alex);

        foreach ([
            ['POST', "/instances/{$this->alexInstance}/approve", []], ['POST', "/instances/{$this->alexInstance}/send-back", ['note' => 'x']],
            ['POST', "/instances/{$this->samInstance}/mark-done", []], ['GET', "/kids/{$this->alexId}/today", []],
            ['PUT', "/chores/{$this->choreId}", ['title' => 'Hacked', 'schedule_type' => 'daily']], ['DELETE', "/chores/{$this->choreId}", []],
        ] as [$method, $path, $body]) {
            [$status] = $this->call($method, $path, $body, $this->parentB);
            $this->assertSame(404, $status, "$method $path");
        }
        $this->assertSame('reported_done', $this->pdo->query("SELECT status FROM chore_instances WHERE id = {$this->alexInstance}")->fetchColumn());
        $this->assertSame('Dishes', $this->pdo->query("SELECT title FROM chores WHERE id = {$this->choreId}")->fetchColumn());

        $this->assertSame([], $this->call('GET', '/chores', [], $this->parentB)[1]['chores']);
        $this->assertSame(0, $this->call('GET', '/approvals', [], $this->parentB)[1]['count']);
        $this->assertSame(1, $this->call('GET', '/approvals', [], $this->parentA)[1]['count']);

        // And they can't assign chores to someone else's kid.
        [$status] = $this->call('POST', '/chores', ['title' => 'X', 'kid_ids' => [$this->alexId]], $this->parentB);
        $this->assertSame(422, $status);
    }

    public function testEitherParentInTheHouseholdCanApprove(): void
    {
        [, $inv] = $this->call('POST', '/invites', [], $this->parentA);
        [, $joined] = $this->call('POST', '/invite/' . $inv['token'] . '/accept', ['email' => 'dad@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'Dad']);
        $dad = $joined['token'];
        $this->call('POST', "/kid/instances/{$this->alexInstance}/done", [], $this->alex);
        [$status] = $this->call('POST', "/instances/{$this->alexInstance}/approve", [], $dad);
        $this->assertSame(200, $status);
        $this->assertSame('parent', $this->pdo->query("SELECT created_by_type FROM ledger_entries")->fetchColumn());
    }

    public function testDeactivatedKidIsSignedOutOfChores(): void
    {
        $this->call('POST', "/kids/{$this->alexId}/active", ['active' => false], $this->parentA);
        [$status] = $this->call('GET', '/kid/today', [], $this->alex);
        $this->assertSame(401, $status);
    }
}
