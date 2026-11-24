<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Clock;
use App\Config;
use App\Http\Request;
use App\Http\Response;
use App\Services;
use PDO;

/** Rewards: the parent's list and decisions, and the kid's Rewards screen. */
final class RewardController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private Services $services)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    // ---- parent ---------------------------------------------------------------------------------

    public function index(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['rewards' => $this->services->rewards->listForParent($ctx['household_id'])]);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(201, ['reward' => $this->services->rewards->create($ctx['household_id'], $r->body)]);
    }

    public function update(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['reward' => $this->services->rewards->update($ctx['household_id'], (int) $p['id'], $r->body)]);
    }

    public function remove(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->services->rewards->remove($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['ok' => true]);
    }

    public function approve(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['request' => $this->services->rewards->approve($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $this->note($r))]);
    }

    public function deny(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['request' => $this->services->rewards->deny($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $this->note($r))]);
    }

    public function delivered(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['request' => $this->services->rewards->delivered($ctx['household_id'], (int) $p['id'])]);
    }

    // ---- kid ------------------------------------------------------------------------------------

    public function kidIndex(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, [
            'rewards' => $this->services->rewards->forKid($ctx['household_id'], $ctx['kid_id']),
            'requests' => $this->services->rewards->requestsForKid($ctx['kid_id']),
        ]);
    }

    public function kidRequest(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(201, ['request' => $this->services->rewards->request($ctx['household_id'], $ctx['kid_id'], (int) $p['id'])]);
    }

    public function kidSave(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(201, ['goal' => $this->services->rewards->saveFor($ctx['household_id'], $ctx['kid_id'], (int) $p['id'])]);
    }

    public function kidCancel(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['request' => $this->services->rewards->cancel($ctx['household_id'], $ctx['kid_id'], (int) $p['id'])]);
    }

    private function note(Request $r): ?string
    {
        $note = $r->input('note');
        return is_string($note) ? $note : null;
    }
}
