<?php
declare(strict_types=1);

namespace Tests;

/** If settling fails the app keeps working, parents see a warning on Home, and a later successful settle clears it. */
final class SettleWarningTest extends RulesTestCase
{
    private bool $triggerInstalled = false;
    private string|false $oldErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldErrorLog = ini_set('error_log', '/dev/null'); // the failure is logged on purpose; keep test output clean
    }

    protected function tearDown(): void
    {
        $this->removeFailure();
        ini_set('error_log', (string) $this->oldErrorLog);
        parent::tearDown();
    }

    /** Make settling fail for real: any new kid_days row (which settling creates) is refused by the database. */
    private function breakSettling(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_settle BEFORE INSERT ON kid_days FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated settle failure'");
        $this->triggerInstalled = true;
    }

    private function removeFailure(): void
    {
        if ($this->triggerInstalled) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS fail_settle');
            $this->triggerInstalled = false;
        }
    }

    private function homeApprovals(): array
    {
        [$status, $body] = $this->call('GET', '/approvals', [], $this->parent);
        $this->assertSame(200, $status, 'the app keeps running: ' . json_encode($body));
        return $body;
    }

    public function testNoWarningWhenSettlingWorks(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->clock->set(self::DEC3);
        $this->assertFalse($this->homeApprovals()['settle_failed']);
        $this->assertNull($this->pdo->query('SELECT settle_failed_at FROM households')->fetchColumn());
    }

    public function testAFailedSettleKeepsTheAppRunningAndWarnsParentsUntilALaterSettleSucceeds(): void
    {
        $this->chore(['title' => 'Make bed', 'required' => true, 'value' => 10, 'currency' => 'points']);
        $this->breakSettling();
        $this->clock->set(self::DEC3); // Nov 30 - Dec 2 are waiting to be settled

        // Settling fails, but the request still succeeds and the parent is warned.
        $first = $this->homeApprovals();
        $this->assertTrue($first['settle_failed']);
        $this->assertNotNull($this->pdo->query('SELECT settle_failed_at FROM households')->fetchColumn());
        $this->assertNull($this->pdo->query('SELECT settled_through FROM households')->fetchColumn(), 'nothing was half-settled');
        $this->assertSame(0, $this->entryCount('forfeit'));
        // Other parent screens keep working too.
        [$status] = $this->call('GET', '/household', [], $this->parent);
        $this->assertSame(200, $status);
        // ...and it stays on while the problem remains.
        $this->assertTrue($this->homeApprovals()['settle_failed']);

        // The problem goes away: the next request settles everything and the warning clears.
        $this->removeFailure();
        $later = $this->homeApprovals();
        $this->assertFalse($later['settle_failed']);
        $this->assertNull($this->pdo->query('SELECT settle_failed_at FROM households')->fetchColumn());
        $this->assertSame('2026-12-02', $this->pdo->query('SELECT settled_through FROM households')->fetchColumn());
        $this->assertSame('forfeited', $this->dayStatus('2026-12-01'));
        $this->assertFalse($this->homeApprovals()['settle_failed'], 'and stays clear');
    }

    public function testSettleScriptReportsTheFailureAndExitsNonZero(): void
    {
        $this->pdo->exec("UPDATE households SET created_at = '2026-10-01 12:00:00'");
        $this->chore(['title' => 'Make bed', 'required' => true]);
        $this->pdo->exec('UPDATE households SET settled_through = NULL'); // days still waiting to be settled
        $this->breakSettling();
        $cfg = sys_get_temp_dir() . '/choretest_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($cfg, '<?php return ' . var_export(['db' => test_db_settings(), 'is_test_site' => false], true) . ';');
        exec('CHORE_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/settle.php') . ' 2>&1', $out, $code);
        unlink($cfg);
        $this->assertNotSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('Settle failed', implode("\n", $out));
        $this->assertNotNull($this->pdo->query('SELECT settle_failed_at FROM households')->fetchColumn(), 'the warning is recorded by the script too');
    }
}
