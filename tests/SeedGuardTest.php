<?php
declare(strict_types=1);

namespace Tests;

use App\Clock;
use App\Config;
use App\Seeder;

final class SeedGuardTest extends ApiTestCase
{
    private function notTestSite(): Config
    {
        return new Config(['db' => test_db_settings(), 'is_test_site' => false]);
    }

    public function testSeedRefusesOutsideTestSite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is_test_site');
        (new Seeder($this->pdo, $this->notTestSite(), $this->clock))->seed();
    }

    public function testResetRefusesOutsideTestSiteAndKeepsData(): void
    {
        $this->registerParent();
        try {
            (new Seeder($this->pdo, $this->notTestSite(), $this->clock))->reset();
            $this->fail('reset should have refused');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn());
    }

    public function testMissingOrNonTrueFlagCountsAsNotATestSite(): void
    {
        foreach ([[], ['is_test_site' => 'true'], ['is_test_site' => 1], ['is_test_site' => null]] as $values) {
            $this->assertFalse((new Config($values))->isTestSite());
        }
    }

    public function testSeedCreatesDemoHouseholdAndIsRepeatable(): void
    {
        $seeder = new Seeder($this->pdo, test_config(), $this->clock);
        $result = $seeder->seed();
        $this->assertTrue($result['created']);
        $this->assertSame(['Alex', 'Sam'], array_keys($result['kid_links']));
        $this->assertFalse($seeder->seed()['created'], 'second run does nothing');

        [$status, $body] = $this->call('POST', '/login', ['email' => 'test@example.com', 'password' => 'Correct-Horse-42']);
        $this->assertSame(200, $status);
        $this->assertSame('America/New_York', $body['household']['timezone']);
        [$status] = $this->call('POST', '/login', ['email' => 'parent2@example.com', 'password' => 'Correct-Horse-42']);
        $this->assertSame(200, $status);
        [$status] = $this->call('POST', '/kid/login', ['link_token' => $result['kid_links']['Sam'], 'pin' => '1234']);
        $this->assertSame(200, $status);
    }

    public function testSeedCreatesTheDemoChoresForBothKids(): void
    {
        $result = (new Seeder($this->pdo, test_config(), $this->clock))->seed();
        $kid = $this->kidToken($result['kid_links']['Alex']);
        $day = $this->kidList($kid);
        $this->assertSame(
            ['Brush teeth / Morning', 'Brush teeth / Evening', 'Feed the fish', 'Make bed', 'Rake the front yard', 'Walk the dog / Morning', 'Walk the dog / Evening'],
            $this->titles($day)
        );
        $byTitle = [];
        foreach ($day['chores'] as $c) {
            $byTitle[$c['title'] . ($c['slot_label'] ? '/' . $c['slot_label'] : '')] = $c;
        }
        $this->assertSame([10, 'points'], [$byTitle['Make bed']['value'], $byTitle['Make bed']['currency']]);
        $this->assertSame([50, 'money'], [$byTitle['Walk the dog/Morning']['value'], $byTitle['Walk the dog/Morning']['currency']]);
        $this->assertSame([500, 'money'], [$byTitle['Rake the front yard']['value'], $byTitle['Rake the front yard']['currency']]);
        $this->assertNull($byTitle['Brush teeth/Morning']['value']);
        $this->assertSame('08:00', $byTitle['Brush teeth/Morning']['due_by']);
        $this->assertTrue($byTitle['Feed the fish']['auto_approve']);
        $this->assertSame('Sunday', date('l', strtotime($byTitle['Rake the front yard']['due_date'])));
        $this->assertGreaterThanOrEqual($day['today'], $byTitle['Rake the front yard']['due_date']);
        $this->assertSame(5, $this->countRows('SELECT COUNT(DISTINCT group_id) FROM chores'));
        $this->assertSame(10, $this->countRows('SELECT COUNT(*) FROM chores'));
    }

    public function testResetWipesDataThenReseeds(): void
    {
        $this->registerParent('someone@example.com');
        $seeder = new Seeder($this->pdo, test_config(), $this->clock);
        $seeder->reset();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn());
        $this->assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), 'migration history kept');
        $this->assertTrue($seeder->seed()['created']);
    }

    /** The real command-line scripts, run as separate processes, with a config file that is NOT a test site. */
    public function testCommandLineScriptsRefuseWithoutTestSiteFlag(): void
    {
        $db = test_db_settings();
        $dir = sys_get_temp_dir() . '/choretest_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $cfg = $dir . '/config.php';
        file_put_contents($cfg, '<?php return ' . var_export(['db' => $db, 'is_test_site' => false], true) . ';');

        $this->registerParent();
        foreach (['seed.php', 'reset.php'] as $script) {
            $cmd = 'CHORE_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(dirname(__DIR__) . '/scripts/' . $script) . ' 2>&1';
            exec($cmd, $out, $code);
            $this->assertNotSame(0, $code, "$script should fail");
            $this->assertStringContainsString('Refusing to run', implode("\n", $out));
            $out = [];
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn(), 'data untouched');
        unlink($cfg);
        rmdir($dir);
    }
}
