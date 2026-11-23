<?php
declare(strict_types=1);

namespace App;

use App\Http\ApiException;
use App\Http\Request;
use App\Support\Tokens;
use PDO;

/** Issues and checks bearer tokens (parents and kids). Tokens are stored only as hashes. */
final class Auth
{
    public const PARENT_TTL = '+30 days';
    public const KID_TTL = '+90 days';

    public function __construct(private PDO $pdo, private Clock $clock) {}

    /** @return string the plaintext token (shown to the client once) */
    public function issue(string $type, int $householdId, ?int $parentId, ?int $kidId): string
    {
        $token = Tokens::generate('ct_');
        $this->pdo->prepare(
            'INSERT INTO auth_tokens (token_hash, type, parent_id, kid_id, household_id, expires_at, revoked, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([
            Tokens::hash($token), $type, $parentId, $kidId, $householdId,
            $this->clock->in($type === 'parent' ? self::PARENT_TTL : self::KID_TTL),
            $this->clock->nowSql(),
        ]);
        return $token;
    }

    /**
     * @param 'parent'|'kid' $type
     * @return array{token_id:int, household_id:int, parent_id:?int, kid_id:?int}
     */
    public function authenticate(Request $request, string $type): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(401, 'Please sign in.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, type, parent_id, kid_id, household_id FROM auth_tokens
             WHERE token_hash = ? AND revoked = 0 AND expires_at > ?'
        );
        $stmt->execute([Tokens::hash($token), $this->clock->nowSql()]);
        $row = $stmt->fetch();
        if (!$row || $row['type'] !== $type) {
            throw new ApiException(401, 'Please sign in again.');
        }
        if ($type === 'kid') {
            $k = $this->pdo->prepare('SELECT active FROM kids WHERE id = ?');
            $k->execute([$row['kid_id']]);
            if ((int) $k->fetchColumn() !== 1) {
                throw new ApiException(401, 'Please sign in again.');
            }
        }
        $this->pdo->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([$this->clock->nowSql(), $row['id']]);
        return [
            'token_id' => (int) $row['id'],
            'household_id' => (int) $row['household_id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'kid_id' => $row['kid_id'] !== null ? (int) $row['kid_id'] : null,
        ];
    }

    public function revoke(int $tokenId): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE id = ?')->execute([$tokenId]);
    }

    public function revokeForKid(int $kidId): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked = 1 WHERE kid_id = ?')->execute([$kidId]);
    }
}
