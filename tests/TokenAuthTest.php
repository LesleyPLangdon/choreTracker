<?php
declare(strict_types=1);

namespace Tests;

final class TokenAuthTest extends ApiTestCase
{
    public function testProtectedRoutesNeedAToken(): void
    {
        foreach ([['GET', '/me'], ['GET', '/household'], ['GET', '/kids'], ['POST', '/invites']] as [$m, $p]) {
            [$status] = $this->call($m, $p);
            $this->assertSame(401, $status, "$m $p");
        }
        [$status] = $this->call('GET', '/me', [], 'ct_garbage');
        $this->assertSame(401, $status);
    }

    public function testMeWorksWithToken(): void
    {
        [$token] = $this->registerParent();
        [$status, $body] = $this->call('GET', '/me', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame('mom@example.com', $body['parent']['email']);
        $this->assertSame('2026-12-01', $body['household']['today']);
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        [$token] = $this->registerParent();
        $rows = $this->pdo->query('SELECT token_hash FROM auth_tokens')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $token), $rows[0]);
        $this->assertStringNotContainsString($token, $rows[0]);
    }

    public function testLogoutRevokesToken(): void
    {
        [$token] = $this->registerParent();
        [$status] = $this->call('POST', '/logout', [], $token);
        $this->assertSame(200, $status);
        [$status] = $this->call('GET', '/me', [], $token);
        $this->assertSame(401, $status);
    }

    public function testTokenExpires(): void
    {
        [$token] = $this->registerParent();
        $this->clock->advance('+31 days');
        [$status] = $this->call('GET', '/me', [], $token);
        $this->assertSame(401, $status);
    }

    public function testKidTokenCannotUseParentRoutesAndViceVersa(): void
    {
        [$parent] = $this->registerParent();
        [, $link] = $this->createKid($parent);
        [, $login] = $this->call('POST', '/kid/login', ['link_token' => $link, 'pin' => '1234']);
        $kidToken = $login['token'];

        [$status] = $this->call('GET', '/kids', [], $kidToken);
        $this->assertSame(401, $status);
        [$status] = $this->call('GET', '/kid/me', [], $parent);
        $this->assertSame(401, $status);
    }

    public function testParentCannotTouchAnotherHouseholdsKid(): void
    {
        [$a] = $this->registerParent('a@example.com');
        [$b] = $this->registerParent('b@example.com');
        [$kidId] = $this->createKid($a);
        foreach ([['PUT', "/kids/$kidId", ['nickname' => 'Hacked']], ['POST', "/kids/$kidId/pin", ['pin' => '9999']],
                  ['POST', "/kids/$kidId/link/reset", []], ['POST', "/kids/$kidId/unlock", []]] as [$m, $p, $body]) {
            [$status] = $this->call($m, $p, $body, $b);
            $this->assertSame(404, $status, "$m $p");
        }
        [, $list] = $this->call('GET', '/kids', [], $b);
        $this->assertCount(0, $list['kids']);
    }
}
