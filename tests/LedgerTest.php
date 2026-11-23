<?php
declare(strict_types=1);

namespace Tests;

use App\Ledger;

final class LedgerTest extends ApiTestCase
{
    public function testBalancesAreComputedFromAppendedEntries(): void
    {
        [$token, $body] = $this->registerParent();
        [$kidId] = $this->createKid($token);
        $hid = $body['household']['id'];
        $ledger = new Ledger($this->pdo, $this->clock);

        $ledger->append($hid, $kidId, 'points', 'pending', 'earning', 50);
        $ledger->append($hid, $kidId, 'points', 'pending', 'unlock', -50);
        $ledger->append($hid, $kidId, 'points', 'spending', 'unlock', 45);
        $ledger->append($hid, $kidId, 'points', 'savings', 'bucket_split', 5);
        $ledger->append($hid, $kidId, 'money', 'spending', 'parent_adjustment', 250, ['note' => 'birthday']);
        $ledger->append($hid, $kidId, 'points', 'spending', 'reward_redemption', -20);

        $this->assertSame(0, $ledger->balance($kidId, 'points', 'pending'));
        $this->assertSame(25, $ledger->balance($kidId, 'points', 'spending'));
        $this->assertSame(5, $ledger->balance($kidId, 'points', 'savings'));
        $this->assertSame(250, $ledger->balance($kidId, 'money', 'spending'), 'currencies stay separate');
        $this->assertSame(25, $ledger->balances($kidId, 'points')['spending']);
    }

    public function testRejectsUnknownValuesAndHasNoUpdateOrDelete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Ledger($this->pdo, $this->clock))->append(1, 1, 'points', 'piggybank', 'earning', 1);
    }

    public function testLedgerClassOffersNoWayToChangeHistory(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(Ledger::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|remove|edit|set)/i', $m);
        }
    }
}
