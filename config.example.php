<?php
// Copy this file to config.php and fill in your values.
// config.php is git-ignored: NEVER commit it (this repository is public).

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'cpaneluser_choretest',
        'user'     => 'cpaneluser_choreuser',
        'password' => 'change-me',
    ],

    // The test site's public address (used when scripts print kid links).
    'app_url' => 'https://test.example.com',

    // true ONLY on the test site. The seed and reset scripts refuse to run
    // unless this is true, so they can never touch a real family's data.
    'is_test_site' => false,

    // Browser-based migration fallback (web/migrate.php). Off unless enabled.
    // When enabled, set a long random key and visit /migrate.php?key=THE_KEY
    'web_migrate' => [
        'enabled' => false,
        'key'     => '',
    ],

    // Allowed browser origins for cross-origin API calls (needed later for
    // the Capacitor phone app). Leave empty while the front end and API are
    // on the same site.
    'cors_allowed_origins' => [],

    // Test site only: freeze "now" at this moment to try end-of-day rules. Write it in your own time zone,
    // e.g. '2026-12-01 23:59:00 America/New_York' (without a zone name it is read as UTC).
    // null = use the real time. Ignored unless is_test_site is true. See README.md.
    'simulated_now' => null,
];
