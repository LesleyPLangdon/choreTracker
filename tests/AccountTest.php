<?php
declare(strict_types=1);

namespace Tests;

final class AccountTest extends ApiTestCase
{
    public function testRegisterCreatesHouseholdAndSignsIn(): void
    {
        [$token, $body] = $this->registerParent();
        $this->assertSame('mom@example.com', $body['parent']['email']);
        $this->assertSame('America/New_York', $body['household']['timezone']);
        $this->assertSame('free', $body['household']['plan']);
        $this->assertSame('00:00', $body['household']['day_end_time']);
        $this->assertStringStartsWith('ct_', $token);

        $hash = $this->pdo->query('SELECT password_hash FROM parents')->fetchColumn();
        $this->assertNotSame('Correct-Horse-42', $hash);
        $this->assertTrue(password_verify('Correct-Horse-42', $hash));
    }

    public function testRegisterRejectsBadInput(): void
    {
        $base = ['email' => 'a@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'A', 'household_name' => 'H', 'timezone' => 'America/Chicago'];
        foreach ([['email' => 'nope'], ['password' => 'short'], ['timezone' => 'Mars/Base'], ['household_name' => ''], ['display_name' => '']] as $bad) {
            [$status] = $this->call('POST', '/register', $bad + $base);
            $this->assertSame(422, $status, json_encode($bad));
        }
    }

    public function testDuplicateEmailIsRejectedCaseInsensitively(): void
    {
        $this->registerParent('mom@example.com');
        [$status, $body] = $this->call('POST', '/register', [
            'email' => 'MOM@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'X',
            'household_name' => 'Other', 'timezone' => 'America/Chicago',
        ]);
        $this->assertSame(409, $status);
        $this->assertArrayHasKey('error', $body);
    }

    public function testLoginSuccessAndFailure(): void
    {
        $this->registerParent();
        [$status, $body] = $this->call('POST', '/login', ['email' => 'Mom@Example.com', 'password' => 'Correct-Horse-42']);
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('token', $body);

        [$status] = $this->call('POST', '/login', ['email' => 'mom@example.com', 'password' => 'wrong-password-1']);
        $this->assertSame(401, $status);
        [$status] = $this->call('POST', '/login', ['email' => 'nobody@example.com', 'password' => 'Correct-Horse-42']);
        $this->assertSame(401, $status);
    }

    public function testHouseholdSettingsUpdate(): void
    {
        [$token] = $this->registerParent();
        [$status, $body] = $this->call('PUT', '/household', ['name' => 'Smith Crew', 'timezone' => 'America/Los_Angeles', 'day_end_time' => '02:30'], $token);
        $this->assertSame(200, $status);
        $this->assertSame('Smith Crew', $body['household']['name']);
        $this->assertSame('America/Los_Angeles', $body['household']['timezone']);
        $this->assertSame('02:30', $body['household']['day_end_time']);

        [$status] = $this->call('PUT', '/household', ['day_end_time' => '25:99'], $token);
        $this->assertSame(422, $status);
        [$status] = $this->call('PUT', '/household', ['timezone' => 'Nope/Nope'], $token);
        $this->assertSame(422, $status);
    }

    public function testInviteLetsSecondParentJoinSameHousehold(): void
    {
        [$token, $first] = $this->registerParent();
        [$status, $inv] = $this->call('POST', '/invites', ['email' => 'dad@example.com'], $token);
        $this->assertSame(201, $status);
        $invite = $inv['token'];

        $stored = $this->pdo->query('SELECT token_hash FROM parent_invites')->fetchColumn();
        $this->assertNotSame($invite, $stored, 'only a hash is stored');

        [$status, $preview] = $this->call('GET', "/invite/$invite");
        $this->assertSame(200, $status);
        $this->assertSame('The Smiths', $preview['household_name']);

        // Wrong email for an email-restricted invite
        [$status] = $this->call('POST', "/invite/$invite/accept", ['email' => 'other@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'Dad']);
        $this->assertSame(422, $status);

        [$status, $joined] = $this->call('POST', "/invite/$invite/accept", ['email' => 'dad@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'Dad']);
        $this->assertSame(201, $status);
        $this->assertSame($first['household']['id'], $joined['household']['id']);

        [$status, $house] = $this->call('GET', '/household', [], $joined['token']);
        $this->assertSame(200, $status);
        $this->assertCount(2, $house['parents']);

        // Single use
        [$status] = $this->call('POST', "/invite/$invite/accept", ['email' => 'dad@example.com', 'password' => 'Correct-Horse-42', 'display_name' => 'Dad']);
        $this->assertSame(404, $status);
    }

    public function testInviteExpiresAndCanBeRevoked(): void
    {
        [$token] = $this->registerParent();
        [, $a] = $this->call('POST', '/invites', [], $token);
        $this->clock->advance('+8 days');
        [$status] = $this->call('GET', '/invite/' . $a['token']);
        $this->assertSame(404, $status, 'expired after 7 days');

        [, $b] = $this->call('POST', '/invites', [], $token);
        $this->call('DELETE', '/invites/' . $b['invite']['id'], [], $token);
        [$status] = $this->call('GET', '/invite/' . $b['token']);
        $this->assertSame(404, $status, 'revoked');
    }
}
