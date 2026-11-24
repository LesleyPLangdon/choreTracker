<?php
declare(strict_types=1);

namespace App\Money;

use App\Chores\ChoreService;
use App\Clock;
use App\Http\ApiException;
use App\HouseholdTime;
use App\Support\Validate;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Challenges: a reward earned by doing chores a set number of times inside a time window. Costs nothing (no ledger entries).
 *
 * A chore instance counts toward a period if it belongs to one of the challenge's chores, was REPORTED within the
 * period's window (start inclusive, end exclusive), and is APPROVED (approval may come after the window closes).
 * Counting ignores forfeits. Periods are created on demand (refresh), like instances and settlement.
 */
final class ChallengeService
{
    private const MAX_WINDOWS = 400; // never walk further back than this many days

    public function __construct(private PDO $pdo, private Clock $clock, private ChoreService $chores) {}

    // ---- creating and listing ---------------------------------------------------------------------

    public function create(int $householdId, array $in): int
    {
        $kidId = $in['kid_id'] ?? null;
        $kid = $this->pdo->prepare('SELECT 1 FROM kids WHERE id = ? AND household_id = ? AND active = 1');
        $kid->execute([$kidId, $householdId]);
        if (!is_int($kidId) || !$kid->fetchColumn()) {
            throw new ApiException(422, 'Choose a kid.');
        }
        $reward = is_string($in['reward_name'] ?? null) ? trim($in['reward_name']) : '';
        if ($reward === '' || mb_strlen($reward) > 100) {
            throw new ApiException(422, 'Say what the reward is (up to 100 characters).');
        }
        $description = is_string($in['reward_description'] ?? null) ? trim($in['reward_description']) : '';
        if (mb_strlen($description) > 255) {
            throw new ApiException(422, 'Keep the description under 255 characters.');
        }
        $target = $in['target_count'] ?? null;
        if (!is_int($target) || $target < 1 || $target > 1000) {
            throw new ApiException(422, 'Choose how many times (at least 1).');
        }
        $groups = $this->cleanGroups($householdId, $kidId, $in['chore_group_ids'] ?? null);
        $window = $this->cleanWindow($in);
        $now = $this->clock->nowSql();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO challenges (household_id, kid_id, reward_name, reward_description, target_count, repeat_type, once_start_local, once_end_local,
                   start_time, end_time, days_of_week, start_weekday, end_weekday, active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([$householdId, $kidId, $reward, $description !== '' ? $description : null, $target, $window['repeat_type'],
                $window['once_start_local'], $window['once_end_local'], $window['start_time'], $window['end_time'], $window['days_of_week'],
                $window['start_weekday'], $window['end_weekday'], $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $link = $this->pdo->prepare('INSERT INTO challenge_chores (challenge_id, chore_group_id) VALUES (?, ?)');
            foreach ($groups as $g) {
                $link->execute([$id, $g]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->refreshChallenge($householdId, $this->find($householdId, $id));
        return $id;
    }

    public function remove(int $householdId, int $challengeId): void
    {
        $this->find($householdId, $challengeId);
        $this->pdo->prepare('UPDATE challenges SET active = 0, updated_at = ? WHERE id = ?')->execute([$this->clock->nowSql(), $challengeId]);
    }

    /** Active challenges with their chores and recent periods. */
    public function listForParent(int $householdId): array
    {
        $this->refreshHousehold($householdId);
        $time = $this->chores->householdTime($householdId);
        $stmt = $this->pdo->prepare(
            'SELECT c.*, k.nickname, k.avatar FROM challenges c JOIN kids k ON k.id = c.kid_id WHERE c.household_id = ? AND c.active = 1 ORDER BY k.nickname, c.id'
        );
        $stmt->execute([$householdId]);
        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $periods = $this->pdo->prepare('SELECT * FROM challenge_periods WHERE challenge_id = ? ORDER BY period_start DESC LIMIT 5');
            $periods->execute([$c['id']]);
            $out[] = $this->json($c, $time) + [
                'kid' => ['id' => (int) $c['kid_id'], 'nickname' => $c['nickname'], 'avatar' => $c['avatar']],
                'periods' => array_map(fn ($p) => $this->periodJson($c, $p, $time), $periods->fetchAll()),
            ];
        }
        return $out;
    }

    /** What the kid sees: each active challenge with progress, deadline, and reward. */
    public function forKid(int $householdId, int $kidId): array
    {
        $this->refreshHousehold($householdId);
        $time = $this->chores->householdTime($householdId);
        $now = $this->clock->nowSql();
        $stmt = $this->pdo->prepare('SELECT * FROM challenges WHERE kid_id = ? AND household_id = ? AND active = 1 ORDER BY id');
        $stmt->execute([$kidId, $householdId]);
        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $periods = $this->pdo->prepare('SELECT * FROM challenge_periods WHERE challenge_id = ? ORDER BY period_start DESC LIMIT 8');
            $periods->execute([$c['id']]);
            $rows = $periods->fetchAll();
            // What to show as the current progress: the window open right now, else an earned reward waiting to be
            // handed over, else a window that has closed but is still being checked.
            $show = null;
            foreach ([
                fn ($p) => in_array($p['status'], ['in_progress', 'earned'], true) && $p['period_start'] <= $now && $now < $p['period_end'],
                fn ($p) => $p['status'] === 'earned',
                fn ($p) => $p['status'] === 'in_progress',
            ] as $test) {
                foreach ($rows as $p) {
                    if ($test($p)) {
                        $show = $p;
                        break 2;
                    }
                }
            }
            $open = $show && $show['status'] === 'in_progress' && $show['period_start'] <= $now && $now < $show['period_end'];
            $out[] = $this->json($c, $time) + [
                'period' => $show ? $this->periodJson($c, $show, $time) : null,
                // Earned rewards still waiting for a parent ("Earned! Ask a parent."), even if a newer window is open.
                'earned' => array_values(array_map(fn ($p) => $this->periodJson($c, $p, $time), array_filter($rows, fn ($p) => $p['status'] === 'earned'))),
                'next_start_local' => $open ? null : $this->nextStartLocal($c, $time),
            ];
        }
        return $out;
    }

    // ---- periods, counting -------------------------------------------------------------------------

    /** Create any periods that should exist now and bring in-progress ones up to date. Idempotent. */
    public function refreshHousehold(int $householdId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM challenges WHERE household_id = ? AND active = 1');
        $stmt->execute([$householdId]);
        foreach ($stmt->fetchAll() as $c) {
            $this->refreshChallenge($householdId, $c);
        }
    }

    private function refreshChallenge(int $householdId, array $c): void
    {
        $time = $this->chores->householdTime($householdId);
        $now = $this->clock->nowSql();
        $insert = $this->pdo->prepare(
            "INSERT INTO challenge_periods (challenge_id, period_start, period_end, status, created_at, updated_at) VALUES (?, ?, ?, 'in_progress', ?, ?)
             ON DUPLICATE KEY UPDATE period_start = VALUES(period_start)"
        );
        foreach ($this->windowsUpTo($c, $time) as [$start, $end]) {
            $insert->execute([$c['id'], $start, $end, $now, $now]);
        }
        $open = $this->pdo->prepare("SELECT * FROM challenge_periods WHERE challenge_id = ? AND status = 'in_progress'");
        $open->execute([$c['id']]);
        foreach ($open->fetchAll() as $p) {
            $this->evaluate($c, $p, $now);
        }
    }

    /** How many approved chore instances count in this period. */
    public function count(array $c, array $p): int
    {
        return $this->countWhere($c, $p, 'approved');
    }

    private function countWhere(array $c, array $p, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM chore_instances i JOIN chores ch ON ch.id = i.chore_id
             JOIN challenge_chores cc ON cc.chore_group_id = ch.group_id AND cc.challenge_id = ?
             WHERE i.kid_id = ? AND i.status = ? AND i.reported_at >= ? AND i.reported_at < ?'
        );
        $stmt->execute([$c['id'], $c['kid_id'], $status, $p['period_start'], $p['period_end']]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Earned as soon as the count reaches the target. After the window closes, missed once every chore reported inside
     * it has been decided (approved or sent back). Chores waiting on a parent keep the period open.
     */
    private function evaluate(array $c, array $p, string $now): void
    {
        $count = $this->count($c, $p);
        if ($count >= (int) $c['target_count']) {
            $this->pdo->prepare("UPDATE challenge_periods SET status = 'earned', earned_at = ?, updated_at = ? WHERE id = ? AND status = 'in_progress'")->execute([$now, $now, $p['id']]);
            return;
        }
        if ($now >= $p['period_end'] && $this->countWhere($c, $p, 'reported_done') === 0) {
            $this->pdo->prepare("UPDATE challenge_periods SET status = 'missed', updated_at = ? WHERE id = ? AND status = 'in_progress'")->execute([$now, $p['id']]);
        }
    }

    // ---- parent decisions ----------------------------------------------------------------------------

    /** Parent override: mark any period earned or missed (e.g. a drive day changed the schedule). */
    public function override(int $householdId, int $parentId, int $periodId, string $outcome): array
    {
        [$c, $p] = $this->findPeriod($householdId, $periodId);
        $now = $this->clock->nowSql();
        if ($outcome === 'earned') {
            if ($p['status'] === 'earned' || $p['status'] === 'delivered') {
                throw new ApiException(409, 'That one is already earned.');
            }
            $this->pdo->prepare("UPDATE challenge_periods SET status = 'earned', earned_at = ?, approved_by_parent_id = ?, updated_at = ? WHERE id = ?")->execute([$now, $parentId, $now, $periodId]);
        } elseif ($outcome === 'missed') {
            if ($p['status'] === 'missed' || $p['status'] === 'delivered') {
                throw new ApiException(409, $p['status'] === 'delivered' ? 'That reward was already delivered.' : 'That one is already marked missed.');
            }
            $this->pdo->prepare("UPDATE challenge_periods SET status = 'missed', earned_at = NULL, approved_by_parent_id = ?, updated_at = ? WHERE id = ?")->execute([$parentId, $now, $periodId]);
        } else {
            throw new ApiException(422, 'Choose earned or missed.');
        }
        return $this->periodJson($c, $this->findPeriod($householdId, $periodId)[1], $this->chores->householdTime($householdId));
    }

    public function delivered(int $householdId, int $parentId, int $periodId): array
    {
        [$c, $p] = $this->findPeriod($householdId, $periodId);
        if ($p['status'] !== 'earned') {
            throw new ApiException(409, "That reward hasn't been earned yet.");
        }
        $now = $this->clock->nowSql();
        $this->pdo->prepare("UPDATE challenge_periods SET status = 'delivered', delivered_at = ?, approved_by_parent_id = ?, updated_at = ? WHERE id = ?")->execute([$now, $parentId, $now, $periodId]);
        return $this->periodJson($c, $this->findPeriod($householdId, $periodId)[1], $this->chores->householdTime($householdId));
    }

    /** Earned rewards waiting to be handed over. */
    public function approvalItems(int $householdId): array
    {
        $this->refreshHousehold($householdId);
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.reward_name, c.kid_id, c.target_count, k.nickname, k.avatar FROM challenge_periods p
             JOIN challenges c ON c.id = p.challenge_id JOIN kids k ON k.id = c.kid_id
             WHERE c.household_id = ? AND c.active = 1 AND p.status = 'earned' ORDER BY p.earned_at DESC, p.id DESC"
        );
        $stmt->execute([$householdId]);
        return array_map(fn ($p) => [
            'type' => 'challenge_earned',
            'id' => (int) $p['id'],
            'kid' => ['id' => (int) $p['kid_id'], 'nickname' => $p['nickname'], 'avatar' => $p['avatar']],
            'reward_name' => $p['reward_name'],
            'target_count' => (int) $p['target_count'],
            'at' => $p['earned_at'],
            'counted' => true,
        ], $stmt->fetchAll());
    }

    // ---- windows ---------------------------------------------------------------------------------------

    /**
     * Every window of this challenge that has already started and hasn't already ended before the challenge was made.
     * (A window the challenge was created in the middle of still counts everything reported inside it.)
     * @return list<array{0:string,1:string}> [start UTC, end UTC]
     */
    private function windowsUpTo(array $c, HouseholdTime $time): array
    {
        $now = $this->clock->nowSql();
        if ($c['repeat_type'] === 'once') {
            $w = [$time->localToUtc($c['once_start_local']), $time->localToUtc($c['once_end_local'])];
            return $w[0] <= $now ? [$w] : [];
        }
        $todayLocal = substr($time->utcToLocal($now), 0, 10);
        $createdLocal = substr($time->utcToLocal($c['created_at']), 0, 10);
        $from = (new DateTimeImmutable($createdLocal))->modify('-7 days')->format('Y-m-d');
        $floor = (new DateTimeImmutable($todayLocal))->modify('-' . self::MAX_WINDOWS . ' days')->format('Y-m-d');
        $out = [];
        foreach ($this->windowsBetween($c, $time, max($from, $floor), $todayLocal) as $w) {
            if ($w[0] <= $now && $w[1] > $c['created_at']) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /**
     * Windows (daily/weekly) whose START falls on a local calendar date in [$fromDay, $toDay].
     * @return list<array{0:string,1:string}>
     */
    public function windowsBetween(array $c, HouseholdTime $time, string $fromDay, string $toDay): array
    {
        $out = [];
        $startTime = $c['start_time'];
        $endTime = $c['end_time'];
        for ($d = new DateTimeImmutable($fromDay); $d->format('Y-m-d') <= $toDay; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            $weekday = (int) $d->format('N');
            if ($c['repeat_type'] === 'daily') {
                if ((int) $c['days_of_week'] & (1 << ($weekday - 1))) {
                    $out[] = [$time->localToUtc("$day $startTime"), $time->localToUtc("$day $endTime")];
                }
            } elseif ($weekday === (int) $c['start_weekday']) {
                $offset = (((int) $c['end_weekday']) - $weekday + 7) % 7;
                if ($offset === 0 && $endTime <= $startTime) {
                    $offset = 7; // same weekday and time: a full week
                }
                $endDay = $d->modify("+$offset days")->format('Y-m-d');
                $out[] = [$time->localToUtc("$day $startTime"), $time->localToUtc("$endDay $endTime")];
            }
        }
        return $out;
    }

    /** When the next window opens (household-local), for challenges with nothing open right now. */
    private function nextStartLocal(array $c, HouseholdTime $time): ?string
    {
        $now = $this->clock->nowSql();
        if ($c['repeat_type'] === 'once') {
            $start = $time->localToUtc($c['once_start_local']);
            return $start > $now ? $time->utcToLocal($start) : null;
        }
        $today = substr($time->utcToLocal($now), 0, 10);
        foreach ($this->windowsBetween($c, $time, $today, (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d')) as $w) {
            if ($w[0] > $now) {
                return $time->utcToLocal($w[0]);
            }
        }
        return null;
    }

    // ---- JSON ---------------------------------------------------------------------------------------------

    private function json(array $c, HouseholdTime $time): array
    {
        $titles = $this->pdo->prepare(
            'SELECT (SELECT ch.title FROM chores ch WHERE ch.group_id = cc.chore_group_id ORDER BY ch.id LIMIT 1) FROM challenge_chores cc WHERE cc.challenge_id = ? ORDER BY 1'
        );
        $titles->execute([$c['id']]);
        $groups = $this->pdo->prepare('SELECT chore_group_id FROM challenge_chores WHERE challenge_id = ? ORDER BY chore_group_id');
        $groups->execute([$c['id']]);
        return [
            'id' => (int) $c['id'],
            'kid_id' => (int) $c['kid_id'],
            'reward_name' => $c['reward_name'],
            'reward_description' => $c['reward_description'],
            'target_count' => (int) $c['target_count'],
            'repeat_type' => $c['repeat_type'],
            'once_start' => $c['once_start_local'] !== null ? substr($c['once_start_local'], 0, 16) : null,
            'once_end' => $c['once_end_local'] !== null ? substr($c['once_end_local'], 0, 16) : null,
            'start_time' => $c['start_time'] !== null ? substr($c['start_time'], 0, 5) : null,
            'end_time' => $c['end_time'] !== null ? substr($c['end_time'], 0, 5) : null,
            'days' => $c['repeat_type'] === 'daily' ? ChoreService::maskToDays((int) $c['days_of_week']) : [],
            'start_weekday' => $c['start_weekday'] !== null ? (int) $c['start_weekday'] : null,
            'end_weekday' => $c['end_weekday'] !== null ? (int) $c['end_weekday'] : null,
            'chores' => array_values(array_filter($titles->fetchAll(PDO::FETCH_COLUMN))),
            'chore_group_ids' => $groups->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    private function periodJson(array $c, array $p, HouseholdTime $time): array
    {
        $now = $this->clock->nowSql();
        return [
            'id' => (int) $p['id'],
            'status' => $p['status'],
            'count' => $this->count($c, $p),
            'target_count' => (int) $c['target_count'],
            'start_local' => $time->utcToLocal($p['period_start']),
            'end_local' => $time->utcToLocal($p['period_end']),
            'open' => $p['period_start'] <= $now && $now < $p['period_end'],
        ];
    }

    private function find(int $householdId, int $challengeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM challenges WHERE id = ? AND household_id = ? AND active = 1');
        $stmt->execute([$challengeId, $householdId]);
        $c = $stmt->fetch();
        if (!$c) {
            throw new ApiException(404, 'Challenge not found.');
        }
        return $c;
    }

    /** @return array{0:array,1:array} [challenge, period] */
    private function findPeriod(int $householdId, int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM challenge_periods p JOIN challenges c ON c.id = p.challenge_id WHERE p.id = ? AND c.household_id = ?'
        );
        $stmt->execute([$periodId, $householdId]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new ApiException(404, 'Challenge period not found.');
        }
        $c = $this->pdo->prepare('SELECT * FROM challenges WHERE id = ?');
        $c->execute([$p['challenge_id']]);
        return [$c->fetch(), $p];
    }

    // ---- validation ------------------------------------------------------------------------------------

    /** @return list<string> */
    private function cleanGroups(int $householdId, int $kidId, mixed $groups): array
    {
        if (!is_array($groups) || !$groups) {
            throw new ApiException(422, 'Choose at least one chore that counts.');
        }
        $check = $this->pdo->prepare('SELECT 1 FROM chores WHERE group_id = ? AND household_id = ? AND kid_id = ? AND active = 1 LIMIT 1');
        $out = [];
        foreach ($groups as $g) {
            if (!is_string($g)) {
                throw new ApiException(422, 'Choose chores from the list.');
            }
            $check->execute([$g, $householdId, $kidId]);
            if (!$check->fetchColumn()) {
                throw new ApiException(422, "Choose chores that are assigned to that kid.");
            }
            $out[$g] = $g;
        }
        return array_values($out);
    }

    /** @return array<string,mixed> */
    private function cleanWindow(array $in): array
    {
        $type = $in['repeat_type'] ?? null;
        $w = ['repeat_type' => $type, 'once_start_local' => null, 'once_end_local' => null, 'start_time' => null, 'end_time' => null,
            'days_of_week' => 127, 'start_weekday' => null, 'end_weekday' => null];
        if ($type === 'once') {
            $start = $this->dateTime($in['once_start'] ?? null, 'Start');
            $end = $this->dateTime($in['once_end'] ?? null, 'End');
            if ($end <= $start) {
                throw new ApiException(422, 'The end must be after the start.');
            }
            return ['once_start_local' => $start, 'once_end_local' => $end] + $w;
        }
        if ($type === 'daily') {
            $start = Validate::time((string) ($in['start_time'] ?? ''), 'Start time');
            $end = Validate::time((string) ($in['end_time'] ?? ''), 'End time');
            if ($end <= $start) {
                throw new ApiException(422, 'A daily window has to end later the same day.');
            }
            $days = $in['days'] ?? null;
            if (!is_array($days) || !$days) {
                throw new ApiException(422, 'Pick at least one day.');
            }
            $mask = 0;
            foreach ($days as $d) {
                if (!is_int($d) || $d < 1 || $d > 7) {
                    throw new ApiException(422, 'Days must be Monday to Sunday.');
                }
                $mask |= 1 << ($d - 1);
            }
            return ['start_time' => $start, 'end_time' => $end, 'days_of_week' => $mask] + $w;
        }
        if ($type === 'weekly') {
            $sd = $in['start_weekday'] ?? null;
            $ed = $in['end_weekday'] ?? null;
            foreach ([$sd, $ed] as $d) {
                if (!is_int($d) || $d < 1 || $d > 7) {
                    throw new ApiException(422, 'Pick the start and end day.');
                }
            }
            return ['start_time' => Validate::time((string) ($in['start_time'] ?? ''), 'Start time'),
                'end_time' => Validate::time((string) ($in['end_time'] ?? ''), 'End time'), 'start_weekday' => $sd, 'end_weekday' => $ed] + $w;
        }
        throw new ApiException(422, 'Choose one time only, daily, or weekly.');
    }

    private function dateTime(mixed $v, string $label): string
    {
        $text = is_string($v) ? str_replace('T', ' ', trim($v)) : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($text, 0, 16), new DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d H:i') !== substr($text, 0, 16)) {
            throw new ApiException(422, "$label must be a date and time.");
        }
        return $parsed->format('Y-m-d H:i:s');
    }
}
