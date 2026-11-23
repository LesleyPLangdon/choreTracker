<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validate;

/** Parent register, login, logout, me. */
final class AccountController extends BaseController
{
    public function register(Request $r): Response
    {
        $email = Validate::email($r->str('email'));
        $password = Validate::password((string) $r->input('password', ''));
        $name = Validate::name($r->str('display_name'), 'Your name', 60);
        $householdName = Validate::name($r->str('household_name'), 'Household name', 100);
        $tz = Validate::timezone($r->str('timezone'));

        try {
            $result = $this->inTransaction(function () use ($email, $password, $name, $householdName, $tz) {
                $now = $this->clock->nowSql();
                $this->pdo->prepare('INSERT INTO households (name, timezone, day_end_time, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$householdName, $tz, '00:00:00', 'free', $now, $now]);
                $householdId = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare('INSERT INTO parents (household_id, email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$householdId, $email, password_hash($password, PASSWORD_DEFAULT), $name, $now]);
                $parentId = (int) $this->pdo->lastInsertId();
                return [$householdId, $parentId];
            });
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                throw new ApiException(409, 'An account with that email already exists. Try signing in.');
            }
            throw $e;
        }
        [$householdId, $parentId] = $result;
        $token = $this->auth->issue('parent', $householdId, $parentId, null);
        return new Response(201, $this->session($token, $parentId));
    }

    public function login(Request $r): Response
    {
        $email = strtolower($r->str('email'));
        $password = (string) $r->input('password', '');
        $stmt = $this->pdo->prepare('SELECT * FROM parents WHERE email = ?');
        $stmt->execute([$email]);
        $parent = $stmt->fetch();
        // Always run a hash check so timing doesn't reveal whether the email exists.
        $hash = $parent['password_hash'] ?? '$2y$10$usesomesillystringforsalt.0000000000000000000000000000';
        $ok = password_verify($password, $hash);
        if (!$parent || !$ok) {
            throw new ApiException(401, 'Email or password is incorrect.');
        }
        if (password_needs_rehash($parent['password_hash'], PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE parents SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $parent['id']]);
        }
        $token = $this->auth->issue('parent', (int) $parent['household_id'], (int) $parent['id'], null);
        return new Response(200, $this->session($token, (int) $parent['id']));
    }

    public function logout(Request $r): Response
    {
        $ctx = $this->parent($r);
        $this->auth->revoke($ctx['token_id']);
        return new Response(200, ['ok' => true]);
    }

    public function me(Request $r): Response
    {
        $ctx = $this->parent($r);
        return new Response(200, $this->session(null, $ctx['parent_id']));
    }

    private function session(?string $token, int $parentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM parents WHERE id = ?');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        $out = [
            'parent' => $this->parentJson($parent),
            'household' => $this->householdJson($this->household((int) $parent['household_id'])),
        ];
        if ($token !== null) {
            $out['token'] = $token;
        }
        return $out;
    }
}
