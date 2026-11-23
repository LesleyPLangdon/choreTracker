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
}
