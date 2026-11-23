<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Tokens;
use App\Support\Validate;

/** A second parent joins a household with a single-use invite link (valid 7 days). */
final class InviteController extends BaseController
{
    private const TTL = '+7 days';

    public function create(Request $r): Response
    {
        $ctx = $this->parent($r);
        $email = $r->str('email') !== '' ? Validate::email($r->str('email')) : null;
        $token = Tokens::generate('inv_');
        $this->pdo->prepare(
            'INSERT INTO parent_invites (household_id, invited_by_parent_id, email, token_hash, expires_at, revoked, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute([$ctx['household_id'], $ctx['parent_id'], $email, Tokens::hash($token), $this->clock->in(self::TTL), $this->clock->nowSql()]);
        return new Response(201, [
            'invite' => ['id' => (int) $this->pdo->lastInsertId(), 'email' => $email, 'expires_at' => $this->clock->in(self::TTL)],
            'token' => $token, // shown once; only its hash is stored
        ]);
    }

    public function index(Request $r): Response
    {
        $ctx = $this->parent($r);
        $stmt = $this->pdo->prepare(
            'SELECT id, email, expires_at, created_at FROM parent_invites
             WHERE household_id = ? AND accepted_at IS NULL AND revoked = 0 AND expires_at > ? ORDER BY id DESC'
        );
        $stmt->execute([$ctx['household_id'], $this->clock->nowSql()]);
        return new Response(200, ['invites' => array_map(fn ($i) => [
            'id' => (int) $i['id'], 'email' => $i['email'], 'expires_at' => $i['expires_at'],
        ], $stmt->fetchAll())]);
    }

    public function revoke(Request $r, array $p): Response
    {
        $ctx = $this->parent($r);
        $this->pdo->prepare('UPDATE parent_invites SET revoked = 1 WHERE id = ? AND household_id = ?')
            ->execute([(int) $p['id'], $ctx['household_id']]);
        return new Response(200, ['ok' => true]);
    }

    /** Public: lets the invite page show which household the person is joining. */
    public function preview(Request $r, array $p): Response
    {
        $invite = $this->findUsable($p['token']);
        $h = $this->household((int) $invite['household_id']);
        return new Response(200, ['household_name' => $h['name'], 'email' => $invite['email']]);
    }

    public function accept(Request $r, array $p): Response
    {
        $invite = $this->findUsable($p['token']);
        $email = Validate::email($r->str('email'));
        $password = Validate::password((string) $r->input('password', ''));
        $name = Validate::name($r->str('display_name'), 'Your name', 60);
        if ($invite['email'] !== null && strtolower($invite['email']) !== $email) {
            throw new ApiException(422, 'This invite was sent to a different email address.');
        }
        try {
            $parentId = $this->inTransaction(function () use ($invite, $email, $password, $name) {
                $now = $this->clock->nowSql();
                $this->pdo->prepare('INSERT INTO parents (household_id, email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$invite['household_id'], $email, password_hash($password, PASSWORD_DEFAULT), $name, $now]);
                $id = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('UPDATE parent_invites SET accepted_at = ?, accepted_parent_id = ? WHERE id = ?')
                    ->execute([$now, $id, $invite['id']]);
                return $id;
            });
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                throw new ApiException(409, 'An account with that email already exists. Try signing in.');
            }
            throw $e;
        }
        $token = $this->auth->issue('parent', (int) $invite['household_id'], $parentId, null);
        return new Response(201, [
            'token' => $token,
            'parent' => ['id' => $parentId, 'email' => $email, 'display_name' => $name],
            'household' => $this->householdJson($this->household((int) $invite['household_id'])),
        ]);
    }

    private function findUsable(string $token): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM parent_invites WHERE token_hash = ? AND accepted_at IS NULL AND revoked = 0 AND expires_at > ?'
        );
        $stmt->execute([Tokens::hash($token), $this->clock->nowSql()]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException(404, 'This invite is no longer valid. Ask for a new one.');
        }
        return $row;
    }
}
