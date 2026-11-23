<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Tokens;
use App\Support\Validate;

/** Parent-side kid management. Kids are profiles: nickname, avatar, PIN, and a private link. */
final class KidController extends BaseController
{
    public function index(Request $r): Response
    {
        $ctx = $this->parent($r);
        $stmt = $this->pdo->prepare('SELECT * FROM kids WHERE household_id = ? ORDER BY active DESC, nickname');
        $stmt->execute([$ctx['household_id']]);
        return new Response(200, [
            'kids' => array_map(fn ($k) => $this->kidJson($k), $stmt->fetchAll()),
            'avatars' => Validate::AVATARS,
        ]);
    }

    public function create(Request $r): Response
    {
        $ctx = $this->parent($r);
        $nickname = Validate::name($r->str('nickname'), 'Nickname', 30);
        $avatar = Validate::avatar($r->str('avatar'));
        $pin = Validate::pin((string) $r->input('pin', ''));
        $link = Tokens::generate('kl_');
        $now = $this->clock->nowSql();
        $this->pdo->prepare(
            'INSERT INTO kids (household_id, nickname, avatar, pin_hash, link_token_hash, active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$ctx['household_id'], $nickname, $avatar, password_hash($pin, PASSWORD_DEFAULT), Tokens::hash($link), $now, $now]);
        $kid = $this->find($ctx['household_id'], (int) $this->pdo->lastInsertId());
        return new Response(201, ['kid' => $this->kidJson($kid), 'link_token' => $link]);
    }

    public function update(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kid = $this->find($ctx['household_id'], (int) $p['id']);
        $nickname = $r->input('nickname') !== null ? Validate::name($r->str('nickname'), 'Nickname', 30) : $kid['nickname'];
        $avatar = $r->input('avatar') !== null ? Validate::avatar($r->str('avatar')) : $kid['avatar'];
        $this->pdo->prepare('UPDATE kids SET nickname = ?, avatar = ?, updated_at = ? WHERE id = ?')
            ->execute([$nickname, $avatar, $this->clock->nowSql(), $kid['id']]);
        return new Response(200, ['kid' => $this->kidJson($this->find($ctx['household_id'], (int) $kid['id']))]);
    }

    public function setActive(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kid = $this->find($ctx['household_id'], (int) $p['id']);
        $active = $r->input('active') === true ? 1 : 0;
        $this->pdo->prepare('UPDATE kids SET active = ?, updated_at = ? WHERE id = ?')
            ->execute([$active, $this->clock->nowSql(), $kid['id']]);
        if (!$active) {
            $this->auth->revokeForKid((int) $kid['id']);
        }
        return new Response(200, ['kid' => $this->kidJson($this->find($ctx['household_id'], (int) $kid['id']))]);
    }

    public function setPin(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kid = $this->find($ctx['household_id'], (int) $p['id']);
        $pin = Validate::pin((string) $r->input('pin', ''));
        $this->pdo->prepare('UPDATE kids SET pin_hash = ?, failed_pin_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $this->clock->nowSql(), $kid['id']]);
        return new Response(200, ['ok' => true]);
    }

    /** Makes a new link; the old one stops working and any signed-in kid device is signed out. */
    public function resetLink(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kid = $this->find($ctx['household_id'], (int) $p['id']);
        $link = Tokens::generate('kl_');
        $this->pdo->prepare('UPDATE kids SET link_token_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([Tokens::hash($link), $this->clock->nowSql(), $kid['id']]);
        $this->auth->revokeForKid((int) $kid['id']);
        return new Response(200, ['kid' => $this->kidJson($this->find($ctx['household_id'], (int) $kid['id'])), 'link_token' => $link]);
    }

    public function unlock(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $kid = $this->find($ctx['household_id'], (int) $p['id']);
        $this->pdo->prepare('UPDATE kids SET failed_pin_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')
            ->execute([$this->clock->nowSql(), $kid['id']]);
        return new Response(200, ['kid' => $this->kidJson($this->find($ctx['household_id'], (int) $kid['id']))]);
    }

    private function find(int $householdId, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM kids WHERE id = ? AND household_id = ?');
        $stmt->execute([$id, $householdId]);
        $kid = $stmt->fetch();
        if (!$kid) {
            throw new ApiException(404, 'Kid not found.');
        }
        return $kid;
    }

    private function kidJson(array $k): array
    {
        $locked = $k['locked_until'] !== null && $k['locked_until'] > $this->clock->nowSql();
        return [
            'id' => (int) $k['id'],
            'nickname' => $k['nickname'],
            'avatar' => $k['avatar'],
            'active' => (bool) $k['active'],
            'locked' => $locked,
            'locked_until' => $locked ? $k['locked_until'] : null,
        ];
    }
}
