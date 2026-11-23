<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Clock;
use App\Database;
use App\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

/** Base class: a clean database and a frozen clock for every test, and a helper to call the API in-process. */
abstract class ApiTestCase extends TestCase
{
    protected PDO $pdo;
    protected Clock $clock;
    protected App $app;

    protected function setUp(): void
    {
        $config = test_config();
        $this->pdo = Database::connect($config);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if ($t !== 'schema_migrations') {
                $this->pdo->exec("TRUNCATE TABLE `$t`");
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->clock = Clock::at('2026-12-01 15:00:00');
        $this->app = new App($this->pdo, $config, $this->clock);
    }

    /** @return array{0:int,1:array} [status, decoded JSON body] */
    protected function call(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $headers = $token !== null ? ['authorization' => "Bearer $token"] : [];
        $response = $this->app->handle(new Request($method, $path, $body, $headers));
        return [$response->status, json_decode($response->json() ?: '[]', true) ?? []];
    }

    /** Registers a parent + household and returns [token, body]. */
    protected function registerParent(string $email = 'mom@example.com', string $timezone = 'America/New_York'): array
    {
        [$status, $body] = $this->call('POST', '/register', [
            'email' => $email, 'password' => 'Correct-Horse-42', 'display_name' => 'Mom',
            'household_name' => 'The Smiths', 'timezone' => $timezone,
        ]);
        $this->assertSame(201, $status, json_encode($body));
        return [$body['token'], $body];
    }

    /** Creates a kid and returns [kidId, linkToken]. */
    protected function createKid(string $parentToken, string $nickname = 'Alex', string $pin = '1234'): array
    {
        [$status, $body] = $this->call('POST', '/kids', ['nickname' => $nickname, 'avatar' => '🦊', 'pin' => $pin], $parentToken);
        $this->assertSame(201, $status, json_encode($body));
        return [$body['kid']['id'], $body['link_token']];
    }

    /** Signs a kid in with their link + PIN and returns the kid token. */
    protected function kidToken(string $link, string $pin = '1234'): string
    {
        [$status, $body] = $this->call('POST', '/kid/login', ['link_token' => $link, 'pin' => $pin]);
        $this->assertSame(200, $status, json_encode($body));
        return $body['token'];
    }

    /** Creates a chore for the given kids and returns the new chore ids. */
    protected function createChore(string $parentToken, array $kidIds, array $fields = []): array
    {
        [$status, $body] = $this->call('POST', '/chores', $fields + ['title' => 'Make bed', 'kid_ids' => $kidIds], $parentToken);
        $this->assertSame(201, $status, json_encode($body));
        return $body['chore_ids'];
    }

    /** The kid's list for today, as the kid sees it. */
    protected function kidList(string $kidToken): array
    {
        [$status, $body] = $this->call('GET', '/kid/today', [], $kidToken);
        $this->assertSame(200, $status, json_encode($body));
        return $body;
    }

    /** Titles (with slot label) on a list, for easy comparison. */
    protected function titles(array $day): array
    {
        return array_map(fn ($c) => $c['title'] . ($c['slot_label'] ? ' / ' . $c['slot_label'] : ''), $day['chores']);
    }

    protected function countRows(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
