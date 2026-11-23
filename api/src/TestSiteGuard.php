<?php
declare(strict_types=1);

namespace App;

/** Seed and reset scripts call this first. They refuse to run unless config says "test site". */
final class TestSiteGuard
{
    public static function assert(Config $config): void
    {
        if (!$config->isTestSite()) {
            throw new \RuntimeException(
                "Refusing to run: config.php does not set 'is_test_site' => true. "
                . 'This command wipes or fills the database with demo data and is only for the test site.'
            );
        }
    }
}
