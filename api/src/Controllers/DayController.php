<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Approvals\ApprovalList;
use App\Chores\InstanceService;
use App\Money\Summary;
use App\Clock;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use PDO;

/** Today's lists, the kid's Done/Undo, and the parent's approvals. */
final class DayController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private InstanceService $instances, private ApprovalList $approvalList, private Summary $summary)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    // ---- kid ----------------------------------------------------------------------------------------

    public function kidToday(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        $stmt = $this->pdo->prepare('SELECT id, nickname, avatar FROM kids WHERE id = ?');
        $stmt->execute([$ctx['kid_id']]);
        $kid = $stmt->fetch();
        $day = $this->instances->dayFor($ctx['household_id'], $ctx['kid_id']);
        $day['notices'] = $this->notices($ctx['kid_id'], $day['today']);
        $day['summary'] = $this->summary->forKid($ctx['household_id'], $ctx['kid_id']);
        return new Response(200, ['kid' => ['id' => (int) $kid['id'], 'nickname' => $kid['nickname'], 'avatar' => $kid['avatar']]] + $day);
    }

    /**
     * Short, neutral notes for the kid: a recent forfeited day they haven't dismissed yet, and any recent day still
     * waiting for a parent to check.
     */
    private function notices(int $kidId, string $today): array
    {
        $since = (new \DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT local_date, status FROM kid_days WHERE kid_id = ? AND local_date < ? AND local_date >= ?
               AND ((status = 'forfeited' AND notified_at IS NULL) OR status = 'waiting') ORDER BY local_date DESC"
        );
        $stmt->execute([$kidId, $today, $since]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['type' => $r['status'] === 'forfeited' ? 'forfeited' : 'waiting', 'date' => $r['local_date']];
        }
        return $out;
    }

    public function kidDone(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['chore' => $this->instances->report($ctx['household_id'], $ctx['kid_id'], (int) $p['id'])]);
    }

    public function kidUndo(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['chore' => $this->instances->undo($ctx['household_id'], $ctx['kid_id'], (int) $p['id'])]);
    }

    // ---- parent -------------------------------------------------------------------------------------

    /** A parent looking at one kid's day. */
    public function parentKidToday(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $stmt = $this->pdo->prepare('SELECT id, nickname, avatar, active FROM kids WHERE id = ? AND household_id = ?');
        $stmt->execute([(int) $p['id'], $ctx['household_id']]);
        $kid = $stmt->fetch();
        if (!$kid) {
            throw new ApiException(404, 'Kid not found.');
        }
        $day = $this->instances->dayFor($ctx['household_id'], (int) $kid['id']);
        return new Response(200, [
            'kid' => ['id' => (int) $kid['id'], 'nickname' => $kid['nickname'], 'avatar' => $kid['avatar'], 'active' => (bool) $kid['active']],
        ] + $day);
    }

    public function approve(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['chore' => $this->instances->approve($ctx['household_id'], $ctx['parent_id'], (int) $p['id'])]);
    }

    public function sendBack(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $note = $r->input('note');
        return new Response(200, ['chore' => $this->instances->sendBack($ctx['household_id'], (int) $p['id'], is_string($note) ? $note : null)]);
    }

    public function markDone(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['chore' => $this->instances->markDone($ctx['household_id'], $ctx['parent_id'], (int) $p['id'])]);
    }

    public function approvals(Request $r): Response
    {
        $ctx = $this->parent($r);
        $failed = $this->pdo->prepare('SELECT settle_failed_at FROM households WHERE id = ?');
        $failed->execute([$ctx['household_id']]);
        // settle_failed: past days couldn't be updated; the screen shows a warning until a later settle succeeds.
        return new Response(200, $this->approvalList->forHousehold($ctx['household_id']) + ['settle_failed' => $failed->fetchColumn() !== null]);
    }

    public function approvalCount(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['count' => $this->approvalList->count($ctx['household_id'])]);
    }
}
