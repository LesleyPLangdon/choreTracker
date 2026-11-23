<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Clock;
use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\HouseholdTime;
use PDO;

abstract class BaseController
{
    public function __construct(
        protected PDO $pdo,
        protected Config $config,
        protected Clock $clock,
        protected Auth $auth,
    ) {}

    /** @return array{token_id:int, household_id:int, parent_id:?int, kid_id:?int} */
    protected function parent(Request $r): array
    {
        return $this->auth->authenticate($r, 'parent');
    }

    protected function household(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM households WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException(404, 'Household not found.');
        }
        return $row;
    }

    protected function householdJson(array $h): array
    {
        return [
            'id' => (int) $h['id'],
            'name' => $h['name'],
            'timezone' => $h['timezone'],
            'day_end_time' => substr($h['day_end_time'], 0, 5),
            'plan' => $h['plan'],
            'today' => HouseholdTime::forHousehold($h, $this->clock)->today(),
        ];
    }

    protected function parentJson(array $p): array
    {
        return ['id' => (int) $p['id'], 'email' => $p['email'], 'display_name' => $p['display_name']];
    }

    protected function inTransaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected static function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062;
    }
}
