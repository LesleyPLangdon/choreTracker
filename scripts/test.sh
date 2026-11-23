#!/usr/bin/env bash
# Runs the whole test suite against a FRESH test database (created and migrated by tests/bootstrap.php).
# Database settings come from DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_NAME (defaults match scripts/setup-dev.sh).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -x vendor/bin/phpunit ]; then
  echo "PHPUnit not installed. Run: composer install   (or scripts/setup-dev.sh)" >&2
  exit 1
fi

echo "PHP $(php -r 'echo PHP_VERSION;')"
echo "Database: ${DB_USER:-choretracker}@${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}/${DB_NAME:-choretracker_test} (dropped and re-created by the test bootstrap)"
exec vendor/bin/phpunit --testdox "$@"
