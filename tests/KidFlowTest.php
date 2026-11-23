<?php
declare(strict_types=1);

namespace Tests;

final class KidFlowTest extends ApiTestCase
{
    private string $parent;
    private int $kidId;
    private string $link;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->parent] = $this->registerParent();
        [$this->kidId, $this->link] = $this->createKid($this->parent);
    }

    public function testKidCreationStoresOnlyHashes(): void
    {
        $row = $this->pdo->query('SELECT pin_hash, link_token_hash FROM kids')->fetch();
        $this->assertTrue(password_verify('1234', $row['pin_hash']));
        $this->assertSame(hash('sha256', $this->link), $row['link_token_hash']);
        $this->assertGreaterThanOrEqual(64, strlen($this->link));
    }

    public function testKidListNeverExposesSecrets(): void
    {
        [, $body] = $this->call('GET', '/kids', [], $this->parent);
        $json = json_encode($body);
        $this->assertStringNotContainsString('pin_hash', $json);
        $this->assertStringNotContainsString('link_token', $json);
        $this->assertStringNotContainsString($this->link, $json);
    }

    public function testLinkPreviewThenPinLogin(): void
    {
        [$status, $preview] = $this->call('GET', '/kid/link/' . $this->link);
        $this->assertSame(200, $status);
        $this->assertSame('Alex', $preview['kid']['nickname']);
        $this->assertSame('🦊', $preview['kid']['avatar']);

        [$status, $login] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(200, $status);
        [$status, $me] = $this->call('GET', '/kid/me', [], $login['token']);
        $this->assertSame(200, $status);
        $this->assertSame('Alex', $me['kid']['nickname']);
        $this->assertSame('2026-12-01', $me['household']['today']);
    }

    public function testBadLinkIsRejected(): void
    {
        [$status] = $this->call('GET', '/kid/link/not-a-real-link');
        $this->assertSame(404, $status);
        [$status] = $this->call('POST', '/kid/login', ['link_token' => 'nope', 'pin' => '1234']);
        $this->assertSame(404, $status);
        [$status] = $this->call('POST', '/kid/login', ['pin' => '1234']);
        $this->assertSame(404, $status);
    }

    public function testWrongPinCountsDownThenLocksForFifteenMinutes(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            [$status, $body] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '0000']);
            $this->assertSame(401, $status);
            $this->assertSame(5 - $i, $body['attempts_left']);
        }
        [$status, $body] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '0000']);
        $this->assertSame(429, $status);
        $this->assertTrue($body['locked']);

        // Even the right PIN is refused while locked.
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(429, $status);
        [, $preview] = $this->call('GET', '/kid/link/' . $this->link);
        $this->assertTrue($preview['kid']['locked']);

        // Parent sees the lock.
        [, $list] = $this->call('GET', '/kids', [], $this->parent);
        $this->assertTrue($list['kids'][0]['locked']);

        // Lock lifts after 15 minutes.
        $this->clock->advance('+14 minutes');
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(429, $status);
        $this->clock->advance('+2 minutes');
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(200, $status);
    }

    public function testSuccessfulLoginResetsTheMissCount(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '0000']);
        }
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(200, $status);
        [$status, $body] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '0000']);
        $this->assertSame(401, $status);
        $this->assertSame(4, $body['attempts_left']);
    }

    public function testParentCanUnlock(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '0000']);
        }
        [$status, $body] = $this->call('POST', "/kids/{$this->kidId}/unlock", [], $this->parent);
        $this->assertSame(200, $status);
        $this->assertFalse($body['kid']['locked']);
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(200, $status);
    }

    public function testResetLinkInvalidatesOldLinkAndSignsOutDevices(): void
    {
        [, $login] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);

        [$status, $reset] = $this->call('POST', "/kids/{$this->kidId}/link/reset", [], $this->parent);
        $this->assertSame(200, $status);
        $newLink = $reset['link_token'];
        $this->assertNotSame($this->link, $newLink);

        [$status] = $this->call('GET', '/kid/link/' . $this->link);
        $this->assertSame(404, $status, 'old link is dead');
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(404, $status);
        [$status] = $this->call('GET', '/kid/me', [], $login['token']);
        $this->assertSame(401, $status, 'old session is signed out');

        [$status] = $this->call('POST', '/kid/login', ['link_token' => $newLink, 'pin' => '1234']);
        $this->assertSame(200, $status);
    }

    public function testChangePinAndEditKid(): void
    {
        [$status] = $this->call('POST', "/kids/{$this->kidId}/pin", ['pin' => '12'], $this->parent);
        $this->assertSame(422, $status);
        [$status] = $this->call('POST', "/kids/{$this->kidId}/pin", ['pin' => '5678'], $this->parent);
        $this->assertSame(200, $status);

        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(401, $status);
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '5678']);
        $this->assertSame(200, $status);

        [$status, $body] = $this->call('PUT', "/kids/{$this->kidId}", ['nickname' => 'Lex', 'avatar' => '🐼'], $this->parent);
        $this->assertSame(200, $status);
        $this->assertSame('Lex', $body['kid']['nickname']);
        [$status] = $this->call('PUT', "/kids/{$this->kidId}", ['avatar' => 'not-an-avatar'], $this->parent);
        $this->assertSame(422, $status);
    }

    public function testDeactivateBlocksLoginAndSignsOut(): void
    {
        [, $login] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        [$status, $body] = $this->call('POST', "/kids/{$this->kidId}/active", ['active' => false], $this->parent);
        $this->assertSame(200, $status);
        $this->assertFalse($body['kid']['active']);

        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(404, $status);
        [$status] = $this->call('GET', '/kid/me', [], $login['token']);
        $this->assertSame(401, $status);

        $this->call('POST', "/kids/{$this->kidId}/active", ['active' => true], $this->parent);
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $this->link, 'pin' => '1234']);
        $this->assertSame(200, $status);
    }
}
