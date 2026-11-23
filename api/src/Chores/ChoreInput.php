<?php
declare(strict_types=1);

namespace App\Chores;

use App\Http\ApiException;
use App\HouseholdTime;
use App\Support\Validate;

/** Validates the chore form (create and edit) and turns it into clean values. */
final class ChoreInput
{
    public const MAX_SLOTS = 6;
    public const MAX_POINTS = 100000;
    public const MAX_CENTS = 1000000; // $10,000

    /**
     * @return array{title:string, value:?int, currency:?string, schedule_type:string, days_of_week:int,
     *               due_date:?string, auto_approve:int, overdue_behavior:string,
     *               slots:list<array{id:?int,label:string,available_from:?string,due_by:?string}>}
     */
    public static function clean(array $in, HouseholdTime $time): array
    {
        $title = Validate::name((string) ($in['title'] ?? ''), 'Chore name', 100);

        // Value: a whole number (points) or cents (money). No value = no currency.
        $value = null;
        $currency = null;
        $rawValue = $in['value'] ?? null;
        if ($rawValue !== null && $rawValue !== '' && $rawValue !== 0) {
            $currency = $in['currency'] ?? null;
            if (!in_array($currency, ['points', 'money'], true)) {
                throw new ApiException(422, 'Choose points or money for the value.');
            }
            if (!is_int($rawValue) || $rawValue < 1) {
                throw new ApiException(422, 'The value must be a whole number greater than zero.');
            }
            $max = $currency === 'points' ? self::MAX_POINTS : self::MAX_CENTS;
            if ($rawValue > $max) {
                throw new ApiException(422, 'That value is too large.');
            }
            $value = $rawValue;
        }

        $type = $in['schedule_type'] ?? 'daily';
        if (!in_array($type, ['daily', 'one_off'], true)) {
            throw new ApiException(422, 'Choose "repeats" or "one time".');
        }

        $mask = 127;
        $dueDate = null;
        if ($type === 'daily') {
            $days = $in['days'] ?? [1, 2, 3, 4, 5, 6, 7];
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
        } elseif (($in['due_date'] ?? '') !== '' && ($in['due_date'] ?? null) !== null) {
            $dueDate = (string) $in['due_date'];
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
            if (!$parsed || $parsed->format('Y-m-d') !== $dueDate) {
                throw new ApiException(422, 'The due date must look like 2026-12-06.');
            }
        }

        $overdue = $in['overdue_behavior'] ?? ($type === 'daily' ? 'expire' : 'carry_over');
        if (!in_array($overdue, ['expire', 'carry_over'], true)) {
            throw new ApiException(422, 'Choose what happens if the chore is not done.');
        }

        return [
            'title' => $title,
            'value' => $value,
            'currency' => $currency,
            'schedule_type' => $type,
            'days_of_week' => $mask,
            'due_date' => $dueDate,
            'auto_approve' => ($in['auto_approve'] ?? false) === true ? 1 : 0,
            'overdue_behavior' => $overdue,
            'slots' => self::slots($in['slots'] ?? null, $time),
        ];
    }

    /** @return list<array{id:?int,label:string,available_from:?string,due_by:?string}> */
    private static function slots(mixed $raw, HouseholdTime $time): array
    {
        if ($raw === null || $raw === []) {
            $raw = [[]]; // most chores have a single, unlabelled slot
        }
        if (!is_array($raw) || count($raw) > self::MAX_SLOTS) {
            throw new ApiException(422, 'A chore can have up to ' . self::MAX_SLOTS . ' times a day.');
        }
        $multiple = count($raw) > 1;
        $out = [];
        foreach (array_values($raw) as $slot) {
            if (!is_array($slot)) {
                throw new ApiException(422, 'Each time of day needs a label.');
            }
            $label = trim((string) ($slot['label'] ?? ''));
            if ($multiple && $label === '') {
                throw new ApiException(422, 'Give each time of day a label, like Morning or Evening.');
            }
            if (mb_strlen($label) > 40) {
                throw new ApiException(422, 'Labels can be up to 40 characters.');
            }
            $from = self::optionalTime($slot['available_from'] ?? null, 'Available from');
            $due = self::optionalTime($slot['due_by'] ?? null, 'Due by');
            if ($from !== null && $due !== null && $time->clockSecondsIntoDay($due) <= $time->clockSecondsIntoDay($from)) {
                throw new ApiException(422, 'The due-by time must be after the available-from time.');
            }
            $id = $slot['id'] ?? null;
            $out[] = [
                'id' => is_int($id) ? $id : null,
                'label' => $label !== '' ? $label : 'Anytime',
                'available_from' => $from,
                'due_by' => $due,
            ];
        }
        return $out;
    }

    private static function optionalTime(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return Validate::time((string) $value, $label);
    }
}
