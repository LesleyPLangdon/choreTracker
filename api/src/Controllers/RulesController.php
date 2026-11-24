<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Clock;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Rules\Overrides;
use PDO;

/** Parent powers over past days (history, back-fill, overrides, balance adjustment) and the kid's note dismissal. */
final class RulesController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private Overrides $overrides)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    public function history(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, $this->overrides->history($ctx['household_id'], (int) $p['id']));
    }

    public function backFill(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $slotId = $r->input('slot_id');
        if (!is_int($slotId)) {
            throw new ApiException(422, 'Choose a chore.');
        }
        $chore = $this->overrides->backFill($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $slotId, $r->str('date'), $this->note($r));
        return new Response(200, ['chore' => $chore]);
    }

    public function unlockDay(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['day' => $this->overrides->unlockDay($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $p['date'], $this->note($r))]);
    }

    public function forfeitDay(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['day' => $this->overrides->forfeitDay($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $p['date'], $this->note($r))]);
    }

    public function undoApproval(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['chore' => $this->overrides->undoApproval($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $this->note($r))]);
    }

    public function clearLate(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['chore' => $this->overrides->clearLate($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], $this->note($r))]);
    }

    public function adjust(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $amount = $r->input('amount');
        if (!is_int($amount)) {
            throw new ApiException(422, 'Enter a whole amount (cents for money).');
        }
        $balances = $this->overrides->adjustBalance($ctx['household_id'], $ctx['parent_id'], (int) $p['id'], (string) $r->input('currency', ''), $amount, $r->str('note'), (string) $r->input('bucket', 'spending'));
        return new Response(200, ['spending' => $balances['spending'], 'balances' => $balances]);
    }

    /** The kid taps OK on the "yesterday's earnings didn't unlock" note. */
    public function ackNotice(Request $r, array $p): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        $this->pdo->prepare('UPDATE kid_days SET notified_at = ? WHERE kid_id = ? AND local_date = ? AND notified_at IS NULL')
            ->execute([$this->clock->nowSql(), $ctx['kid_id'], $p['date']]);
        return new Response(200, ['ok' => true]);
    }

    private function note(Request $r): ?string
    {
        $note = $r->input('note');
        return is_string($note) && trim($note) !== '' ? $note : null;
    }
}
