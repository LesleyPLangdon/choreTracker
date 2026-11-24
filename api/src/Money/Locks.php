<?php
declare(strict_types=1);

namespace App\Money;

use PDO;

/** Money moves for one kid run one at a time: lock the kid's row first, check balances, then append entries. */
final class Locks
{
    public static function kid(PDO $pdo, int $kidId, callable $fn): mixed
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM kids WHERE id = ? FOR UPDATE')->execute([$kidId]);
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
