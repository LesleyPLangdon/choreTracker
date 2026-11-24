<?php
declare(strict_types=1);

namespace App\Money;

use App\Clock;
use App\Http\ApiException;
use App\Ledger;
use PDO;

/**
 * Parent money actions on a kid's buckets: pay out of Spending (money only), mark Savings transferred, mark Giving
 * given, and adjust any bucket. Nothing here ever takes a bucket below zero. Every change is an appended ledger entry.
 */
final class Wallet
{
    private const MAX = ['points' => 1000000, 'money' => 10000000];

    public function __construct(private PDO $pdo, private Clock $clock, private Ledger $ledger) {}

    /** @return array<string,array<string,int>> bucket => currency => balance */
    public function balances(int $kidId): array
    {
        $out = [];
        foreach (['spending', 'savings', 'giving'] as $bucket) {
            foreach (['points', 'money'] as $currency) {
                $out[$bucket][$currency] = $this->ledger->balance($kidId, $currency, $bucket);
            }
        }
        return $out;
    }

    /** "I gave her $5": money only, out of Spending, never more than the balance. */
    public function payOut(int $householdId, int $parentId, int $kidId, int $cents, ?string $note): array
    {
        return $this->move($householdId, $parentId, $kidId, 'money', 'spending', $cents, 'payout', $note, 'payout');
    }

    /** The parent moved Savings into a real savings account. */
    public function markTransferred(int $householdId, int $parentId, int $kidId, string $currency, int $amount, ?string $note): array
    {
        return $this->move($householdId, $parentId, $kidId, $currency, 'savings', $amount, 'savings_transfer', $note, 'savings');
    }

    /** The parent gave Giving money away. */
    public function markGiven(int $householdId, int $parentId, int $kidId, string $currency, int $amount, ?string $note): array
    {
        return $this->move($householdId, $parentId, $kidId, $currency, 'giving', $amount, 'giving_given', $note, 'giving');
    }

    /** Add (+) or subtract (−) from Spending, Savings, or Giving, with a note. Never below zero. */
    public function adjust(int $householdId, int $parentId, int $kidId, string $bucket, string $currency, int $amount, string $note): array
    {
        if (!in_array($bucket, ['spending', 'savings', 'giving'], true)) {
            throw new ApiException(422, 'Choose Spending, Savings, or Giving.');
        }
        $this->checkCurrency($currency);
        if ($amount === 0 || abs($amount) > self::MAX[$currency]) {
            throw new ApiException(422, 'Enter an amount to add or subtract.');
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 200) {
            throw new ApiException(422, 'Add a short note explaining the change.');
        }
        return $this->locked($kidId, function () use ($householdId, $parentId, $kidId, $bucket, $currency, $amount, $note) {
            if ($this->ledger->balance($kidId, $currency, $bucket) + $amount < 0) {
                throw new ApiException(422, 'That would take ' . ucfirst($bucket) . ' below zero.');
            }
            $this->ledger->append($householdId, $kidId, $currency, $bucket, 'parent_adjustment', $amount, [
                'note' => $note, 'created_by_type' => 'parent', 'created_by_id' => $parentId,
                'batch' => $this->ledger->newBatch(), 'action' => 'adjust',
            ]);
            return $this->balances($kidId);
        });
    }

    private function move(int $householdId, int $parentId, int $kidId, string $currency, string $bucket, int $amount, string $action, ?string $note, string $label): array
    {
        $this->checkCurrency($currency);
        if ($amount < 1 || $amount > self::MAX[$currency]) {
            throw new ApiException(422, 'Enter an amount greater than zero.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 200) {
            throw new ApiException(422, 'Keep the note under 200 characters.');
        }
        return $this->locked($kidId, function () use ($householdId, $parentId, $kidId, $currency, $bucket, $amount, $action, $note, $label) {
            if ($this->ledger->balance($kidId, $currency, $bucket) < $amount) {
                throw new ApiException(422, "That's more than is in " . ucfirst($label) . '.');
            }
            $this->ledger->append($householdId, $kidId, $currency, $bucket, 'payout_transfer', -$amount, [
                'note' => $note !== '' ? $note : null, 'created_by_type' => 'parent', 'created_by_id' => $parentId,
                'batch' => $this->ledger->newBatch(), 'action' => $action,
            ]);
            return $this->balances($kidId);
        });
    }

    private function checkCurrency(string $currency): void
    {
        if (!in_array($currency, ['points', 'money'], true)) {
            throw new ApiException(422, 'Choose points or money.');
        }
    }

    private function locked(int $kidId, callable $fn): mixed
    {
        return Locks::kid($this->pdo, $kidId, $fn);
    }
}
