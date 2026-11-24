<?php
declare(strict_types=1);

namespace App\Money;

use App\Chores\ChoreService;
use App\Clock;
use PDO;

/**
 * The plain-language activity list: the last 30 days of ledger moves, worded from the ledger itself. Nothing here is
 * stored; every line is built from ledger entries (grouped by the batch that created them) every time.
 */
final class Activity
{
    private const MAX_GROUPS = 300;

    public function __construct(private PDO $pdo, private Clock $clock, private ChoreService $chores) {}

    /** @return list<array{at:string, at_local:string, kind:string, text:string}> newest first */
    public function forKid(int $householdId, int $kidId, bool $forKid = false, int $days = 30): array
    {
        $time = $this->chores->householdTime($householdId);
        $since = $this->clock->in("-$days days");
        $stmt = $this->pdo->prepare('SELECT * FROM ledger_entries WHERE kid_id = ? AND created_at >= ? ORDER BY id');
        $stmt->execute([$kidId, $since]);
        $entries = $stmt->fetchAll();

        $groups = [];
        foreach ($entries as $e) {
            // Entries from one move share a batch. (Older entries from before batches existed are grouped by what they were.)
            $key = $e['batch_id'] ?? ('legacy|' . ($e['entry_type'] === 'earning' || $e['entry_type'] === 'parent_adjustment' ? $e['id'] : $e['entry_type'] . '|' . $e['local_date'] . '|' . $e['created_at'] . '|' . $e['currency']));
            $groups[$key][] = $e;
        }
        $names = $this->names($entries);

        $out = [];
        foreach (array_reverse($groups) as $group) {
            $text = $this->describe($group, $names, $forKid);
            if ($text === null) {
                continue;
            }
            $first = $group[0];
            $out[] = [
                'at' => $first['created_at'],
                'at_local' => $time->utcToLocal($first['created_at']),
                'kind' => $first['action'] ?? $first['entry_type'],
                'text' => $text,
            ];
            if (count($out) >= self::MAX_GROUPS) {
                break;
            }
        }
        return $out;
    }

    // ---- wording ---------------------------------------------------------------------------------

    /** @param list<array> $g the entries of one move */
    private function describe(array $g, array $names, bool $forKid): ?string
    {
        $e = $g[0];
        $action = $e['action'] ?? null;
        $actions = array_unique(array_column($g, 'action'));
        $cur = $e['currency'];
        $note = $e['note'] !== null && $e['note'] !== '' ? ' — ' . $e['note'] : '';
        $goal = fn (array $row) => $names['goals'][$row['goal_id']] ?? 'a goal';
        $day = $e['local_date'] !== null ? $this->dayName($e['local_date']) : null;

        // Legacy (pre-batch) groups are recognised by their entry types.
        if ($action === null) {
            $action = match ($e['entry_type']) {
                'earning' => 'earning',
                'unlock', 'bucket_split' => 'day_unlock',
                'forfeit' => 'day_forfeit',
                'parent_adjustment' => 'adjust',
                default => null,
            };
            if ($action === null) {
                return null;
            }
        }

        switch ($action) {
            case 'earning':
                $title = $names['chores'][$e['chore_instance_id']] ?? null;
                return 'Earned ' . $this->amt($e['amount'], $cur) . ($title ? " — $title" : '') . ($day ? " ($day)" : '');
            case 'earning_undone':
                $title = $names['chores'][$e['chore_instance_id']] ?? null;
                return 'Approval undone: ' . $this->amt(abs((int) $e['amount']), $cur) . ' taken back' . ($title ? " — $title" : '');
            case 'day_unlock':
                $total = 0;
                $parts = [];
                foreach ($g as $row) {
                    if ($row['bucket'] === 'pending') {
                        $total += abs((int) $row['amount']);
                    } elseif ($row['bucket'] !== 'pending' && (int) $row['amount'] > 0) {
                        $parts[$row['bucket']] = ($parts[$row['bucket']] ?? 0) + (int) $row['amount'];
                    }
                }
                $words = [];
                foreach (['savings' => 'Savings', 'giving' => 'Giving', 'spending' => 'Spending'] as $bucket => $label) {
                    if (!empty($parts[$bucket])) {
                        $words[] = $this->plain($parts[$bucket], $cur) . " to $label";
                    }
                }
                return ($day ? "$day: " : '') . 'Unlocked ' . $this->amt($total, $cur) . ($words ? ' — ' . implode(', ', $words) : '');
            case 'day_forfeit':
                $parts = [];
                foreach ($g as $row) {
                    $parts[] = $this->amt(abs((int) $row['amount']), $cur) . ($row['bucket'] === 'pending' ? '' : ' from ' . ucfirst($row['bucket']));
                }
                return ($day ? "$day" : 'A day') . ' was forfeited: ' . implode(', ', $parts) . ' ' . ($forKid ? 'didn\'t unlock' : 'taken back');
            case 'forfeit_reversed':
                return ($day ? "$day: " : '') . 'Forfeit reversed — ' . $this->amt((int) $e['amount'], $cur) . ' available to unlock';
            case 'goal_in':
                return 'Moved ' . $this->amt(abs((int) $e['amount']), $cur) . ' to goal: ' . $goal($e);
            case 'goal_out':
                return 'Moved ' . $this->amt(abs((int) $e['amount']), $cur) . ' back from goal: ' . $goal($e) . ' to Spending';
            case 'goal_move':
                $from = null;
                $to = null;
                foreach ($g as $row) {
                    if ((int) $row['amount'] < 0) {
                        $from = $row;
                    } else {
                        $to = $row;
                    }
                }
                return 'Moved ' . $this->amt(abs((int) $e['amount']), $cur) . ' from goal ' . ($from ? $goal($from) : 'a goal') . ' to goal ' . ($to ? $goal($to) : 'a goal');
            case 'goal_used':
            case 'goal_leftover':
                $used = 0;
                $left = 0;
                foreach ($g as $row) {
                    if ($row['bucket'] === 'goal' && $row['action'] === 'goal_used') {
                        $used += abs((int) $row['amount']);
                    }
                    if ($row['bucket'] === 'spending') {
                        $left += (int) $row['amount'];
                    }
                }
                return 'Goal used: ' . $goal($e) . ($used ? ' — ' . $this->amt($used, $cur) : '')
                    . ($left ? ', ' . $this->amt($left, $cur) . ' left over went back to Spending' : '');
            case 'goal_cancel':
                return 'Goal cancelled: ' . $goal($e) . ' — ' . $this->amt((int) array_sum(array_map(fn ($r) => $r['bucket'] === 'spending' ? (int) $r['amount'] : 0, $g)), $cur) . ' back to Spending';
            case 'reward_paid':
                $fromGoal = 0;
                $fromSpending = 0;
                foreach ($g as $row) {
                    if ($row['bucket'] === 'goal' && $row['entry_type'] === 'reward_redemption') {
                        $fromGoal += abs((int) $row['amount']);
                    }
                    if ($row['bucket'] === 'spending' && (int) $row['amount'] < 0) {
                        $fromSpending += abs((int) $row['amount']);
                    }
                }
                $name = $names['rewards'][$e['reward_request_id']] ?? 'a reward';
                $detail = $fromGoal && $fromSpending ? ' (' . $this->amt($fromGoal, $cur) . ' from the goal, ' . $this->amt($fromSpending, $cur) . ' from Spending)'
                    : ($fromGoal ? ' (from the goal)' : '');
                return 'Reward: ' . $name . ' — ' . $this->amt($fromGoal + $fromSpending, $cur) . $detail;
            case 'payout':
                return 'Paid out ' . $this->amt(abs((int) $e['amount']), $cur) . $note;
            case 'savings_transfer':
                return 'Moved ' . $this->amt(abs((int) $e['amount']), $cur) . ' from Savings to a real account' . $note;
            case 'giving_given':
                return 'Gave ' . $this->amt(abs((int) $e['amount']), $cur) . ' from Giving' . $note;
            case 'adjust':
                $who = $e['created_by_type'] === 'parent' ? ($names['parents'][$e['created_by_id']] ?? 'A parent') : 'A parent';
                $amount = (int) $e['amount'];
                return ($forKid ? 'A parent' : $who) . ($amount >= 0 ? ' added ' : ' removed ') . $this->amt(abs($amount), $cur)
                    . ($amount >= 0 ? ' to ' : ' from ') . ($forKid ? 'your ' : '') . ucfirst($e['bucket']) . $note;
            default:
                return null;
        }
    }

    private function amt(int $amount, string $currency): string
    {
        return $currency === 'money' ? '$' . number_format($amount / 100, 2) : $amount . ($amount === 1 ? ' point' : ' points');
    }

    /** Like amt(), but a bare "13" for points inside "13 to Spending" would read oddly, so keep the unit. */
    private function plain(int $amount, string $currency): string
    {
        return $this->amt($amount, $currency);
    }

    /** '2026-12-01' → 'Tue'. */
    private function dayName(string $day): string
    {
        return (new \DateTimeImmutable($day))->format('D');
    }

    /** Look up the names the wording needs, in a few queries. */
    private function names(array $entries): array
    {
        $ids = fn (string $col) => array_values(array_unique(array_filter(array_column($entries, $col))));
        $names = ['goals' => [], 'chores' => [], 'rewards' => [], 'parents' => []];
        $fill = function (string $key, string $sql, array $in) use (&$names) {
            if (!$in) {
                return;
            }
            $stmt = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($in), '?'))));
            $stmt->execute($in);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $name]) {
                $names[$key][(int) $id] = $name;
            }
        };
        $fill('goals', 'SELECT id, name FROM goals WHERE id IN (%s)', $ids('goal_id'));
        $fill('chores', 'SELECT i.id, c.title FROM chore_instances i JOIN chores c ON c.id = i.chore_id WHERE i.id IN (%s)', $ids('chore_instance_id'));
        $fill('rewards', 'SELECT q.id, r.name FROM reward_requests q JOIN rewards r ON r.id = q.reward_id WHERE q.id IN (%s)', $ids('reward_request_id'));
        $parents = array_values(array_unique(array_map(fn ($e) => $e['created_by_id'], array_filter($entries, fn ($e) => $e['created_by_type'] === 'parent' && $e['created_by_id'] !== null))));
        $fill('parents', 'SELECT id, display_name FROM parents WHERE id IN (%s)', $parents);
        return $names;
    }
}
