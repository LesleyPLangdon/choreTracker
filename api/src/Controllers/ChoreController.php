<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Chores\ChoreService;
use App\Chores\InstanceService;
use App\Clock;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use PDO;

/** Parent: create, list, edit, and remove chores. */
final class ChoreController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth,
                                private ChoreService $chores, private InstanceService $instances)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    public function index(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['chores' => $this->chores->listGroups($ctx['household_id'])]);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->parent($r);
        $ids = $this->chores->create($ctx['household_id'], $r->body);
        // Show it on the kids' lists right away.
        $stmt = $this->pdo->prepare('SELECT DISTINCT kid_id FROM chores WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $kidId) {
            $this->instances->ensureToday($ctx['household_id'], (int) $kidId);
        }
        return new Response(201, ['chore_ids' => $ids]);
    }

    public function update(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->chores->update($ctx['household_id'], (int) $p['id'], $r->body, $this->applyTo($r));
        return new Response(200, ['ok' => true]);
    }

    public function remove(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->chores->remove($ctx['household_id'], (int) $p['id'], $this->applyTo($r));
        return new Response(200, ['ok' => true]);
    }

    private function applyTo(Request $r): string
    {
        $v = $r->input('apply_to', 'all');
        if (!in_array($v, ['all', 'one'], true)) {
            throw new ApiException(422, 'apply_to must be "all" or "one".');
        }
        return $v;
    }
}
