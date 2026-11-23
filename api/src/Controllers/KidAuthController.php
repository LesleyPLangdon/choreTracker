<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Tokens;

/** Kid side: open link, enter PIN, get a kid token. */
final class KidAuthController extends BaseController
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MODIFIER = '+15 minutes';

    /** Public: lets the PIN screen show the kid's nickname and avatar for a link. */
    public function preview(Request $r, array $p): Response
    {
        $kid = $this->findByLink($p['token']);
        return new Response(200, ['kid' => [
            'nickname' => $kid['nickname'], 'avatar' => $kid['avatar'], 'locked' => $this->isLocked($kid),
        ]]);
    }

    public function login(Request $r): Response
    {
        $kid = $this->findByLink($r->str('link_token'));
        $pin = (string) $r->input('pin', '');

        if ($this->isLocked($kid)) {
            $this->throwLocked($kid);
        }
        if (!password_verify($pin, $kid['pin_hash'])) {
            $attempts = (int) $kid['failed_pin_attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->pdo->prepare('UPDATE kids SET failed_pin_attempts = 0, locked_until = ? WHERE id = ?')
                    ->execute([$this->clock->in(self::LOCK_MODIFIER), $kid['id']]);
                $kid['locked_until'] = $this->clock->in(self::LOCK_MODIFIER);
                $this->throwLocked($kid);
            }
            $this->pdo->prepare('UPDATE kids SET failed_pin_attempts = ? WHERE id = ?')->execute([$attempts, $kid['id']]);
            throw new ApiException(401, 'That PIN is not right.', ['attempts_left' => self::MAX_ATTEMPTS - $attempts]);
        }

        $this->pdo->prepare('UPDATE kids SET failed_pin_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$kid['id']]);
        $token = $this->auth->issue('kid', (int) $kid['household_id'], null, (int) $kid['id']);
        return new Response(200, ['token' => $token, 'kid' => $this->kidJson($kid)]);
    }

    public function me(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        $stmt = $this->pdo->prepare('SELECT * FROM kids WHERE id = ?');
        $stmt->execute([$ctx['kid_id']]);
        $h = $this->household($ctx['household_id']);
        return new Response(200, [
            'kid' => $this->kidJson($stmt->fetch()),
            'household' => ['name' => $h['name'], 'today' => $this->householdJson($h)['today']],
        ]);
    }

    public function logout(Request $r): Response
    {
        $ctx = $this->auth->authenticate($r, 'kid');
        $this->auth->revoke($ctx['token_id']);
        return new Response(200, ['ok' => true]);
    }

    private function findByLink(string $token): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM kids WHERE link_token_hash = ? AND active = 1');
        $stmt->execute([Tokens::hash($token)]);
        $kid = $stmt->fetch();
        if ($token === '' || !$kid) {
            throw new ApiException(404, "This link isn't working anymore. Ask a parent for a new one.");
        }
        return $kid;
    }

    private function isLocked(array $kid): bool
    {
        return $kid['locked_until'] !== null && $kid['locked_until'] > $this->clock->nowSql();
    }

    private function throwLocked(array $kid): never
    {
        $seconds = max(1, strtotime($kid['locked_until'] . ' UTC') - $this->clock->now()->getTimestamp());
        throw new ApiException(429, 'Too many wrong tries. Please wait a bit, or ask a parent to unlock you.', [
            'locked' => true, 'retry_after_seconds' => $seconds,
        ]);
    }

    private function kidJson(array $k): array
    {
        return ['id' => (int) $k['id'], 'nickname' => $k['nickname'], 'avatar' => $k['avatar']];
    }
}
