<?php
declare(strict_types=1);

namespace Tests;

use App\Ledger;

/** Done / undo / approve / send back / auto-approve, lateness, available-from, earnings. */
final class ChoreFlowTest extends ApiTestCase
{
    private string $parent;
    private int $kidId;
    private string $kid;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp(); // Tue 2026-12-01 15:00:00 UTC = 10:00 AM New York
        [$this->parent, $reg] = $this->registerParent();
        $this->householdId = $reg['household']['id'];
        [$this->kidId, $link] = $this->createKid($this->parent);
        $this->kid = $this->kidToken($link);
    }

    private function onlyRow(): array
    {
        return $this->kidList($this->kid)['chores'][0];
    }

    private function done(int $id, ?string $token = null): array
    {
        return $this->call('POST', "/kid/instances/$id/done", [], $token ?? $this->kid);
    }

    private function approve(int $id): array
    {
        return $this->call('POST', "/instances/$id/approve", [], $this->parent);
    }

    private function earnings(): array
    {
        return $this->pdo->query("SELECT * FROM ledger_entries WHERE entry_type = 'earning' ORDER BY id")->fetchAll();
    }

    // ---- done / undo -------------------------------------------------------------------------------

    public function testKidReportsDoneAndItWaitsForApproval(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $id = $this->onlyRow()['id'];
        [$status, $body] = $this->done($id);
        $this->assertSame(200, $status);
        $this->assertSame('reported_done', $body['chore']['status']);
        $this->assertSame('2026-12-01 15:00:00', $this->pdo->query('SELECT reported_at FROM chore_instances')->fetchColumn());
        [, $count] = $this->call('GET', '/approvals/count', [], $this->parent);
        $this->assertSame(1, $count['count']);
    }

    public function testAvailableFromBlocksDoneUntilTheSlotOpens(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['slots' => [['label' => 'Evening', 'available_from' => '18:00']]]);
        $row = $this->onlyRow();
        $this->assertFalse($row['can_report']);
        $this->assertSame('18:00', $row['opens_at']);

        [$status, $body] = $this->done($row['id']);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('6:00 PM', $body['error']);
        $this->assertSame('assigned', $this->onlyRow()['status']);

        $this->clock->set('2026-12-01 23:00:00'); // 6:00 PM in New York, exactly
        $this->assertTrue($this->onlyRow()['can_report']);
        [$status] = $this->done($row['id']);
        $this->assertSame(200, $status);
    }

    public function testLateFlagIsSetAfterDueByNotBefore(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Dressed', 'slots' => [['label' => 'Morning', 'due_by' => '10:00']]]);
        $id = $this->onlyRow()['id'];

        // Exactly at 10:00:00 is not late...
        [, $body] = $this->done($id);
        $this->assertFalse($body['chore']['is_late']);
        $this->call('POST', "/kid/instances/$id/undo", [], $this->kid);

        // ...one second after is.
        $this->clock->set('2026-12-01 15:00:01');
        $this->assertTrue($this->onlyRow()['past_due_by']);
        [, $body] = $this->done($id);
        $this->assertTrue($body['chore']['is_late']);
        $this->assertSame(1, $this->countRows('SELECT is_late FROM chore_instances'));
    }

    public function testLateFlagIsNotSetBeforeDueBy(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['slots' => [['label' => 'Morning', 'due_by' => '11:00']]]);
        [, $body] = $this->done($this->onlyRow()['id']);
        $this->assertFalse($body['chore']['is_late']);
    }

    public function testAfterDueByTheKidCanStillMarkDone(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $row = $this->onlyRow();
        $this->assertTrue($row['can_report']);
        $this->assertTrue($row['past_due_by']);
        [$status, $body] = $this->done($row['id']);
        $this->assertSame(200, $status);
        $this->assertTrue($body['chore']['is_late']);
    }

    public function testUndoClearsReportedAtAndLateFlag(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        [$status, $body] = $this->call('POST', "/kid/instances/$id/undo", [], $this->kid);
        $this->assertSame(200, $status);
        $this->assertSame('assigned', $body['chore']['status']);
        $row = $this->pdo->query('SELECT reported_at, is_late FROM chore_instances')->fetch();
        $this->assertNull($row['reported_at']);
        $this->assertSame(0, (int) $row['is_late']);
        [, $count] = $this->call('GET', '/approvals/count', [], $this->parent);
        $this->assertSame(0, $count['count']);
    }

    public function testUndoIsRefusedAfterApprovalAndWhenNothingWasReported(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['value' => 10, 'currency' => 'points']);
        $id = $this->onlyRow()['id'];
        [$status] = $this->call('POST', "/kid/instances/$id/undo", [], $this->kid);
        $this->assertSame(409, $status, 'not reported yet');

        $this->done($id);
        $this->approve($id);
        [$status, $body] = $this->call('POST', "/kid/instances/$id/undo", [], $this->kid);
        $this->assertSame(409, $status);
        $this->assertSame('approved', $this->onlyRow()['status']);
        $this->assertCount(1, $this->earnings(), 'earning untouched');
    }

    public function testCannotReportTwiceOrAfterApproval(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        [$status] = $this->done($id);
        $this->assertSame(409, $status);
    }

    // ---- send back ---------------------------------------------------------------------------------

    public function testSendBackWithNoteThenKidReportsAgain(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $id = $this->onlyRow()['id'];
        $this->done($id);

        [$status, $body] = $this->call('POST', "/instances/$id/send-back", ['note' => "The bed isn't made"], $this->parent);
        $this->assertSame(200, $status);
        $this->assertSame('sent_back', $body['chore']['status']);
        $row = $this->onlyRow();
        $this->assertSame('sent_back', $row['status']);
        $this->assertSame("The bed isn't made", $row['send_back_note']);
        $this->assertTrue($row['can_report']);
        [, $count] = $this->call('GET', '/approvals/count', [], $this->parent);
        $this->assertSame(0, $count['count'], 'no longer waiting for approval');

        [$status, $body] = $this->done($id);
        $this->assertSame(200, $status);
        $this->assertSame('reported_done', $body['chore']['status']);
        $this->assertNull($body['chore']['send_back_note']);
        [$status] = $this->approve($id);
        $this->assertSame(200, $status);
    }

    public function testSendBackWithoutNote(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        [$status, $body] = $this->call('POST', "/instances/$id/send-back", [], $this->parent);
        $this->assertSame(200, $status);
        $this->assertNull($body['chore']['send_back_note']);
        $this->assertTrue($this->onlyRow()['can_report']);
        [$status] = $this->done($id);
        $this->assertSame(200, $status);
    }

    public function testSendBackNoteIsLimitedAndOnlyForReportedChores(): void
    {
        $this->createChore($this->parent, [$this->kidId]);
        $id = $this->onlyRow()['id'];
        [$status] = $this->call('POST', "/instances/$id/send-back", [], $this->parent);
        $this->assertSame(409, $status, 'not reported yet');
        $this->done($id);
        [$status] = $this->call('POST', "/instances/$id/send-back", ['note' => str_repeat('x', 201)], $this->parent);
        $this->assertSame(422, $status);
    }

    // ---- auto-approve and earnings --------------------------------------------------------------

    public function testAutoApproveGoesStraightToApprovedWithAnEarning(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Feed fish', 'value' => 5, 'currency' => 'points', 'auto_approve' => true]);
        $id = $this->onlyRow()['id'];
        [, $body] = $this->done($id);
        $this->assertSame('approved', $body['chore']['status']);
        $row = $this->pdo->query('SELECT approved_by_parent_id, approved_at FROM chore_instances')->fetch();
        $this->assertNull($row['approved_by_parent_id']);
        $this->assertNotNull($row['approved_at']);
        $this->assertCount(1, $this->earnings());
        [, $count] = $this->call('GET', '/approvals/count', [], $this->parent);
        $this->assertSame(0, $count['count']);
    }

    public function testApprovalWritesExactlyOnePendingEarningInPoints(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['value' => 10, 'currency' => 'points']);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        $this->assertCount(0, $this->earnings(), 'nothing until a parent approves');
        $this->approve($id);
        $this->approve($id); // repeated request
        $this->approve($id);
        $e = $this->earnings();
        $this->assertCount(1, $e);
        $this->assertSame('points', $e[0]['currency']);
        $this->assertSame('pending', $e[0]['bucket']);
        $this->assertSame(10, (int) $e[0]['amount']);
        $this->assertSame($id, (int) $e[0]['chore_instance_id']);
        $this->assertSame('2026-12-01', $e[0]['local_date']);
        $this->assertSame('parent', $e[0]['created_by_type']);
        // No required chores on this day, so the earning moved straight on to Spending (Phase 3).
        $ledger = new Ledger($this->pdo, $this->clock);
        $this->assertSame(0, $ledger->balance($this->kidId, 'points', 'pending'));
        $this->assertSame(10, $ledger->balance($this->kidId, 'points', 'spending'));
    }

    public function testMoneyIsRecordedInCents(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['value' => 50, 'currency' => 'money']);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        $this->approve($id);
        $this->assertSame(50, (new Ledger($this->pdo, $this->clock))->balance($this->kidId, 'money', 'spending'));
        $this->assertSame(0, (new Ledger($this->pdo, $this->clock))->balance($this->kidId, 'points', 'spending'));
        $day = $this->kidList($this->kid);
        $this->assertSame(['points' => 0, 'money' => 50], $day['earned_today']);
    }

    public function testChoreWithNoValueWritesNoEarning(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Brush teeth']);
        $id = $this->onlyRow()['id'];
        $this->done($id);
        $this->approve($id);
        $this->assertCount(0, $this->earnings());
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testEarnedTodayAddsUpPerCurrency(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'A', 'value' => 10, 'currency' => 'points']);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'B', 'value' => 5, 'currency' => 'points', 'auto_approve' => true]);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'C', 'value' => 250, 'currency' => 'money']);
        foreach ($this->kidList($this->kid)['chores'] as $row) {
            $this->done($row['id']);
        }
        $this->assertSame(['points' => 5, 'money' => 0], $this->kidList($this->kid)['earned_today'], 'only the auto-approved one so far');
        foreach ($this->call('GET', '/approvals', [], $this->parent)[1]['items'] as $item) {
            $this->approve($item['id']);
        }
        $this->assertSame(['points' => 15, 'money' => 250], $this->kidList($this->kid)['earned_today']);
        [, $parentView] = $this->call('GET', "/kids/{$this->kidId}/today", [], $this->parent);
        $this->assertSame(['points' => 15, 'money' => 250], $parentView['earned_today']);
    }

    public function testParentMarkDoneApprovesInOneStepAndIsNotLate(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['value' => 10, 'currency' => 'points', 'slots' => [['label' => 'Morning', 'due_by' => '08:00']]]);
        $id = $this->onlyRow()['id'];
        [$status, $body] = $this->call('POST', "/instances/$id/mark-done", [], $this->parent);
        $this->assertSame(200, $status);
        $this->assertSame('approved', $body['chore']['status']);
        $this->assertFalse($body['chore']['is_late']);
        $this->assertCount(1, $this->earnings());
        $this->call('POST', "/instances/$id/mark-done", [], $this->parent);
        $this->assertCount(1, $this->earnings(), 'still one');
    }

    public function testApprovalsListIsNewestFirstWithLateTagAndTypedItems(): void
    {
        [$kid2, $link2] = $this->createKid($this->parent, 'Sam');
        $this->createChore($this->parent, [$this->kidId, $kid2], ['title' => 'Dishes', 'slots' => [['label' => 'Evening', 'due_by' => '09:00']]]);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Bed', 'value' => 10, 'currency' => 'points']);
        $rows = $this->kidList($this->kid)['chores'];
        $dishes = array_values(array_filter($rows, fn ($r) => $r['title'] === 'Dishes'))[0];
        $bed = array_values(array_filter($rows, fn ($r) => $r['title'] === 'Bed'))[0];
        $this->done($dishes['id']);
        $this->clock->advance('+5 minutes');
        $this->done($bed['id']);
        $this->clock->advance('+5 minutes');
        $samDishes = $this->kidList($this->kidToken($link2))['chores'][0];
        $this->done($samDishes['id'], $this->kidToken($link2));

        [$status, $body] = $this->call('GET', '/approvals', [], $this->parent);
        $this->assertSame(200, $status);
        $this->assertSame(3, $body['count']);
        $this->assertSame(['Dishes', 'Bed', 'Dishes'], array_column($body['items'], 'title'));
        $this->assertSame(['Sam', 'Alex', 'Alex'], array_map(fn ($i) => $i['kid']['nickname'], $body['items']));
        $this->assertSame('chore', $body['items'][0]['type']);
        $this->assertTrue($body['items'][0]['is_late']);
        $this->assertFalse($body['items'][1]['is_late']);
        $this->assertSame(10, $body['items'][1]['value']);
    }

    // ---- editing -------------------------------------------------------------------------------------

    public function testEditingDoesNotChangeReportedOrApprovedInstances(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Walk dog', 'value' => 10, 'currency' => 'points', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $rows = $this->kidList($this->kid)['chores'];
        $morning = $rows[0]['id'];
        $evening = $rows[1]['id'];
        $this->done($morning);
        $this->approve($morning);
        $choreId = (int) $this->pdo->query('SELECT id FROM chores')->fetchColumn();
        $slots = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0]['slots'];

        [$status] = $this->call('PUT', "/chores/$choreId", [
            'title' => 'Walk the dog', 'value' => 99, 'currency' => 'money', 'schedule_type' => 'daily', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'slots' => [['id' => $slots[0]['id'], 'label' => 'Morning'], ['id' => $slots[1]['id'], 'label' => 'Evening']],
        ], $this->parent);
        $this->assertSame(200, $status);

        $approved = $this->pdo->query("SELECT value, currency, status FROM chore_instances WHERE id = $morning")->fetch();
        $this->assertSame(['10', 'points', 'approved'], [(string) $approved['value'], $approved['currency'], $approved['status']]);
        $untouched = $this->pdo->query("SELECT value, currency FROM chore_instances WHERE id = $evening")->fetch();
        $this->assertSame(['99', 'money'], [(string) $untouched['value'], $untouched['currency']], "today's untouched to-do picks up the change");
        $this->assertSame(10, (new Ledger($this->pdo, $this->clock))->balance($this->kidId, 'points', 'spending'));

        // A reported-but-unapproved instance keeps its details too.
        $this->done($evening);
        $this->call('PUT', "/chores/$choreId", [
            'title' => 'Walk the dog', 'value' => 1, 'currency' => 'points', 'schedule_type' => 'daily',
            'slots' => [['id' => $slots[0]['id'], 'label' => 'Morning'], ['id' => $slots[1]['id'], 'label' => 'Evening']],
        ], $this->parent);
        $this->assertSame('99', (string) $this->pdo->query("SELECT value FROM chore_instances WHERE id = $evening")->fetchColumn());
    }

    public function testEditingAGroupChangesAllCopiesUnlessOneIsChosen(): void
    {
        [$kid2] = $this->createKid($this->parent, 'Sam');
        $ids = $this->createChore($this->parent, [$this->kidId, $kid2], ['title' => 'Dishes', 'value' => 5, 'currency' => 'points']);
        $body = fn (string $title) => ['title' => $title, 'value' => 5, 'currency' => 'points', 'schedule_type' => 'daily'];

        $this->call('PUT', "/chores/{$ids[0]}", $body('Dishes+'), $this->parent);
        $this->assertSame(['Dishes+', 'Dishes+'], $this->pdo->query('SELECT title FROM chores ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

        $this->call('PUT', "/chores/{$ids[0]}", $body('Only Alex') + ['apply_to' => 'one'], $this->parent);
        $this->assertSame(['Only Alex', 'Dishes+'], $this->pdo->query('SELECT title FROM chores ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEditingOneKidsCopyOfAMultiTimeChoreUsesThatCopysOwnSlots(): void
    {
        [$kid2] = $this->createKid($this->parent, 'Sam');
        $this->createChore($this->parent, [$this->kidId, $kid2], ['title' => 'Walk dog', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $group = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0];
        $this->assertCount(2, $group['kids']);
        $sam = array_values(array_filter($group['kids'], fn ($k) => $k['kid_id'] === $kid2))[0];
        $this->assertCount(2, $sam['slot_ids']);

        // Change just Sam's copy, referring to Sam's own slot ids.
        [$status] = $this->call('PUT', "/chores/{$sam['chore_id']}", [
            'title' => 'Walk dog', 'schedule_type' => 'daily', 'apply_to' => 'one',
            'slots' => [['id' => $sam['slot_ids'][0], 'label' => 'Early'], ['id' => $sam['slot_ids'][1], 'label' => 'Evening']],
        ], $this->parent);
        $this->assertSame(200, $status);
        $labels = fn (int $kid) => $this->pdo->query("SELECT s.label FROM chore_slots s JOIN chores c ON c.id = s.chore_id WHERE c.kid_id = $kid AND s.active = 1 ORDER BY s.sort_order")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['Early', 'Evening'], $labels($kid2));
        $this->assertSame(['Morning', 'Evening'], $labels($this->kidId));

        // Change all copies using the first copy's slot ids: the other copy is matched by position.
        $slots = $group['slots'];
        $this->call('PUT', "/chores/{$group['id']}", [
            'title' => 'Walk dog', 'schedule_type' => 'daily',
            'slots' => [['id' => $slots[0]['id'], 'label' => 'Dawn'], ['id' => $slots[1]['id'], 'label' => 'Dusk']],
        ], $this->parent);
        $this->assertSame(['Dawn', 'Dusk'], $labels($this->kidId));
        $this->assertSame(['Dawn', 'Dusk'], $labels($kid2));

        // A slot id that isn't part of the chore being edited is refused.
        [$status] = $this->call('PUT', "/chores/{$sam['chore_id']}", [
            'title' => 'Walk dog', 'schedule_type' => 'daily', 'apply_to' => 'one', 'slots' => [['id' => $slots[0]['id'], 'label' => 'X']],
        ], $this->parent);
        $this->assertSame(422, $status);
    }

    public function testEditingSlotsAddsAndDeactivatesWithoutLosingHistory(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Teeth', 'slots' => [['label' => 'Morning'], ['label' => 'Evening']]]);
        $rows = $this->kidList($this->kid)['chores'];
        $this->done($rows[0]['id']); // Morning reported
        $choreId = (int) $this->pdo->query('SELECT id FROM chores')->fetchColumn();
        $slots = $this->call('GET', '/chores', [], $this->parent)[1]['chores'][0]['slots'];

        // Keep only Morning (reported) and drop Evening (untouched).
        $this->call('PUT', "/chores/$choreId", ['title' => 'Teeth', 'schedule_type' => 'daily', 'slots' => [['id' => $slots[0]['id'], 'label' => 'Morning']]], $this->parent);
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_instances'), "today's untouched evening to-do is gone");
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM chore_slots WHERE active = 0'), 'slot deactivated, not deleted');
        $this->assertSame('reported_done', $this->pdo->query('SELECT status FROM chore_instances')->fetchColumn());

        // Add a new slot: it shows up on the next load.
        $this->call('PUT', "/chores/$choreId", ['title' => 'Teeth', 'schedule_type' => 'daily', 'slots' => [
            ['id' => $slots[0]['id'], 'label' => 'Morning'], ['label' => 'Bedtime'],
        ]], $this->parent);
        $this->assertSame(['Teeth / Morning', 'Teeth / Bedtime'], $this->titles($this->kidList($this->kid)));
    }

    public function testRemovingDeactivatesAndKeepsHistory(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Bed', 'value' => 10, 'currency' => 'points']);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Dishes']);
        $rows = $this->kidList($this->kid)['chores'];
        $bed = $rows[0]['id'];
        $this->done($bed);
        $this->approve($bed);
        $ids = $this->pdo->query('SELECT id FROM chores ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            [$status] = $this->call('DELETE', "/chores/$id", [], $this->parent);
            $this->assertSame(200, $status);
        }
        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM chores'), 'rows kept');
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM chores WHERE active = 1'));
        $this->assertSame(['Bed'], $this->titles($this->kidList($this->kid)), 'approved history stays visible today; the untouched to-do is gone');
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM ledger_entries WHERE entry_type = 'earning'"));
        $this->assertSame([], $this->call('GET', '/chores', [], $this->parent)[1]['chores']);
    }

    public function testChoreFormValidationAndNoSwitchingBetweenRepeatingAndOneTime(): void
    {
        $bad = [
            ['title' => ''], ['value' => 0.5, 'currency' => 'points'], ['value' => 5], ['value' => -3, 'currency' => 'money'],
            ['schedule_type' => 'weekly'], ['days' => []], ['days' => [8]], ['kid_ids' => []],
            ['schedule_type' => 'one_off', 'due_date' => 'tomorrow'],
            ['slots' => [['label' => ''], ['label' => 'B']]],
            ['slots' => [['label' => 'A', 'available_from' => '18:00', 'due_by' => '08:00']]],
            ['slots' => [['label' => 'A', 'due_by' => '25:00']]],
        ];
        foreach ($bad as $fields) {
            [$status] = $this->call('POST', '/chores', $fields + ['title' => 'X', 'kid_ids' => [$this->kidId]], $this->parent);
            $this->assertSame(422, $status, json_encode($fields));
        }
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM chores'));

        $id = $this->createChore($this->parent, [$this->kidId])[0];
        [$status] = $this->call('PUT', "/chores/$id", ['title' => 'X', 'schedule_type' => 'one_off'], $this->parent);
        $this->assertSame(422, $status);
    }

    public function testDefaultsForWhatHappensIfNotDone(): void
    {
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Daily']);
        $this->createChore($this->parent, [$this->kidId], ['title' => 'Once', 'schedule_type' => 'one_off']);
        $rows = $this->pdo->query('SELECT title, overdue_behavior FROM chores ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['Daily' => 'expire', 'Once' => 'carry_over'], $rows);
    }
}
