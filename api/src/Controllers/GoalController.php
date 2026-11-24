<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Clock;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Services;
use PDO;

/** Goals: the kid's own screens and the parent's approvals and edits. */
final class GoalController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private Services $services)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    // ---- kid ------------------------------------------------------------------------------------

    public function kidIndex(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, [
            'goals' => $this->services->goals->forKid($ctx['kid_id']),
            'can_add' => $this->services->goals->hasRoom($ctx['household_id'], $ctx['kid_id']),
            'spending' => $this->services->summary->forKid($ctx['household_id'], $ctx['kid_id'])['spending'],
            'currencies' => $this->services->currencies->inUse($ctx['kid_id']),
        ]);
    }

    public function kidCreate(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(201, ['goal' => $this->services->goals->create($ctx['household_id'], $ctx['kid_id'], $r->body, null)]);
    }

    public function kidEdit(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['goal' => $this->services->goals->kidEdit($ctx['household_id'], $ctx['kid_id'], (int) $p['id'], $this->edits($r))]);
    }

    public function kidMove(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        $amount = $r->input('amount');
        $to = $r->input('to_goal_id');
        if (!is_int($amount)) {
            throw new ApiException(422, 'Enter a whole amount.');
        }
        return new Response(200, ['goal' => $this->services->goals->move(
            $ctx['household_id'], $ctx['kid_id'], (int) $p['id'], (string) $r->input('action', ''), $amount, is_int($to) ? $to : null
        )]);
    }

    public function kidCancel(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['goal' => $this->services->goals->cancel($ctx['household_id'], (int) $p['id'], $ctx['kid_id'], null)]);
    }

    // ---- parent ---------------------------------------------------------------------------------

    public function index(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->requireKid($ctx['household_id'], (int) $p['id']);
        return new Response(200, [
            'goals' => $this->services->goals->forKid((int) $p['id']),
            'can_add' => $this->services->goals->hasRoom($ctx['household_id'], (int) $p['id']),
        ]);
    }

    public function create(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->requireKid($ctx['household_id'], (int) $p['id']);
        return new Response(201, ['goal' => $this->services->goals->create($ctx['household_id'], (int) $p['id'], $r->body, $ctx['parent_id'])]);
    }

    public function update(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->parentEdit($ctx['household_id'], (int) $p['id'], $this->edits($r) + array_intersect_key($r->body, ['currency' => 1]))]);
    }

    public function approve(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->approve($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $this->edits($r), $this->note($r))]);
    }

    public function deny(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->deny($ctx['household_id'], (int) $p['id'], $this->note($r))]);
    }

    public function approveChange(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->approveChange($ctx['household_id'], (int) $p['id'], $this->edits($r))]);
    }

    public function denyChange(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->denyChange($ctx['household_id'], (int) $p['id'])]);
    }

    public function used(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->markUsed($ctx['household_id'], $ctx['parent_id'], (int) $p['id'])]);
    }

    public function cancel(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['goal' => $this->services->goals->cancel($ctx['household_id'], (int) $p['id'], null, $ctx['parent_id'])]);
    }

    // ---------------------------------------------------------------------------------------------

    /** Only the fields a goal edit may carry (a missing key means "leave it", a null link means "remove it"). */
    private function edits(Request $r): array
    {
        return array_intersect_key($r->body, ['name' => 1, 'target' => 1, 'link' => 1]);
    }

    private function note(Request $r): ?string
    {
        $note = $r->input('note');
        return is_string($note) ? $note : null;
    }

    private function requireKid(int $householdId, int $kidId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM kids WHERE id = ? AND household_id = ?');
        $stmt->execute([$kidId, $householdId]);
        if (!$stmt->fetchColumn()) {
            throw new ApiException(404, 'Kid not found.');
        }
    }
}
