<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Validate;

final class HouseholdController extends BaseController
{
    public function show(Request $r): Response
    {
        $ctx = $this->parent($r);
        $stmt = $this->pdo->prepare('SELECT * FROM parents WHERE household_id = ? ORDER BY id');
        $stmt->execute([$ctx['household_id']]);
        return new Response(200, [
            'household' => $this->householdJson($this->household($ctx['household_id'])),
            'parents' => array_map(fn ($p) => $this->parentJson($p), $stmt->fetchAll()),
        ]);
    }

    public function update(Request $r): Response
    {
        $ctx = $this->parent($r);
        $h = $this->household($ctx['household_id']);
        $name = $r->input('name') !== null ? Validate::name($r->str('name'), 'Household name', 100) : $h['name'];
        $tz = $r->input('timezone') !== null ? Validate::timezone($r->str('timezone')) : $h['timezone'];
        $end = $r->input('day_end_time') !== null ? Validate::time($r->str('day_end_time'), 'End of day') : $h['day_end_time'];
        $this->pdo->prepare('UPDATE households SET name = ?, timezone = ?, day_end_time = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $tz, $end, $this->clock->nowSql(), $h['id']]);
        return new Response(200, ['household' => $this->householdJson($this->household((int) $h['id']))]);
    }
}
