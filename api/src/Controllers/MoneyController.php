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

/** Buckets, payouts, transfers, activity, and the money overview. */
final class MoneyController extends BaseController
{
    public function __construct(PDO $pdo, Config $config, Clock $clock, Auth $auth, private Services $services)
    {
        parent::__construct($pdo, $config, $clock, $auth);
    }

    // ---- parent ---------------------------------------------------------------------------------

    /** Everything about a kid's money in one place. */
    public function overview(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        $summary = $this->services->summary->forKid($ctx['household_id'], $kidId);
        return new Response(200, [
            'summary' => $summary,
            'buckets' => $this->services->buckets->all($kidId),
            'goals' => $this->services->goals->forKid($kidId),
            'can_add_goal' => $this->services->goals->hasRoom($ctx['household_id'], $kidId),
            'activity' => $this->services->activity->forKid($ctx['household_id'], $kidId),
        ]);
    }

    public function buckets(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, ['buckets' => $this->services->buckets->all($this->kid($ctx['household_id'], (int) $p['id']))]);
    }

    public function setBuckets(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        foreach (['points', 'money'] as $currency) {
            $in = $r->input($currency);
            if (is_array($in)) {
                $s = $in['savings_pct'] ?? 0;
                $g = $in['giving_pct'] ?? 0;
                if (!is_int($s) || !is_int($g)) {
                    throw new ApiException(422, 'Percentages must be whole numbers.');
                }
                $this->services->buckets->set($kidId, $currency, $s, $g);
            }
        }
        return new Response(200, ['buckets' => $this->services->buckets->all($kidId)]);
    }

    public function payout(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['balances' => $this->services->wallet->payOut($ctx['household_id'], $ctx['parent_id'], $kidId, $this->amount($r), $this->note($r))]);
    }

    public function savingsTransfer(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['balances' => $this->services->wallet->markTransferred($ctx['household_id'], $ctx['parent_id'], $kidId, (string) $r->input('currency', ''), $this->amount($r), $this->note($r))]);
    }

    public function givingGiven(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['balances' => $this->services->wallet->markGiven($ctx['household_id'], $ctx['parent_id'], $kidId, (string) $r->input('currency', ''), $this->amount($r), $this->note($r))]);
    }

    public function activity(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kidId = $this->kid($ctx['household_id'], (int) $p['id']);
        return new Response(200, ['activity' => $this->services->activity->forKid($ctx['household_id'], $kidId)]);
    }

    // ---- kid ------------------------------------------------------------------------------------

    public function kidSummary(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['summary' => $this->services->summary->forKid($ctx['household_id'], $ctx['kid_id'])]);
    }

    public function kidActivity(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        return new Response(200, ['activity' => $this->services->activity->forKid($ctx['household_id'], $ctx['kid_id'], true)]);
    }

    // ---------------------------------------------------------------------------------------------

    private function amount(Request $r): int
    {
        $amount = $r->input('amount');
        if (!is_int($amount)) {
            throw new ApiException(422, 'Enter a whole amount (cents for money).');
        }
        return $amount;
    }

    private function note(Request $r): ?string
    {
        $note = $r->input('note');
        return is_string($note) ? $note : null;
    }

    private function kid(int $householdId, int $kidId): int
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM kids WHERE id = ? AND household_id = ?');
        $stmt->execute([$kidId, $householdId]);
        if (!$stmt->fetchColumn()) {
            throw new ApiException(404, 'Kid not found.');
        }
        return $kidId;
    }
}
