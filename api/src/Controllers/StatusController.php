<?php
declare(strict_types=1);

namespace App\Controllers;

use App\HouseholdTime;
use App\Http\Request;
use App\Http\Response;
use App\Support\Tokens;

/** Public: lets every screen show the "Simulated time" banner (test site only) using the API's clock, not the device's. */
final class StatusController extends BaseController
{
    public function clock(Request $r): Response
    {
        $simulated = $this->config->isTestSite() && is_string($this->config->get('simulated_now')) && $this->config->get('simulated_now') !== '';
        $out = ['simulated' => $simulated, 'utc' => $this->clock->nowSql(), 'local' => null, 'timezone' => null];
        if ($simulated && ($token = $r->bearerToken()) !== null) {
            // Show the household's local time when we can tell whose screen this is (no side effects).
            $stmt = $this->pdo->prepare(
                'SELECT h.timezone, h.day_end_time FROM auth_tokens t JOIN households h ON h.id = t.household_id
                 WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > ?'
            );
            $stmt->execute([Tokens::hash($token), $this->clock->nowSql()]);
            if ($h = $stmt->fetch()) {
                $out['timezone'] = $h['timezone'];
                $out['local'] = HouseholdTime::forHousehold($h, $this->clock)->utcToLocal($this->clock->nowSql());
            }
        }
        return new Response(200, $out);
    }
}
