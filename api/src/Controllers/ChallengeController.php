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

/** Challenges: the parent creates them and decides outcomes; the kid sees progress. */
final class ChallengeController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private Services $services)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    public function index(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['challenges' => $this->services->challenges->listForParent($ctx['household_id'])]);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(201, ['challenge_id' => $this->services->challenges->create($ctx['household_id'], $r->body)]);
    }

    public function remove(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->services->challenges->remove($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['ok' => true]);
    }

    public function earned(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['period' => $this->services->challenges->override($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], 'earned')]);
    }

    public function missed(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['period' => $this->services->challenges->override($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], 'missed')]);
    }

    public function delivered(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['period' => $this->services->challenges->delivered($ctx['household_id'], $ctx['parent_id'], (int) $p['id'])]);
    }

    public function kidIndex(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['challenges' => $this->services->challenges->forKid($ctx['household_id'], $ctx['kid_id'])]);
    }
}
