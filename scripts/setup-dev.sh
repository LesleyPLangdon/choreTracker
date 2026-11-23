#!/usr/bin/env bash
# Sets up PHP + a MySQL-compatible database for running the tests (Claude Code's environment, or any Linux box).
# Prefers real MySQL 5.7 in Docker; falls back to MariaDB from apt. Safe to run more than once.
#
# Afterwards, run:  scripts/test.sh
set -uo pipefail
cd "$(dirname "$0")/.."

DB_USER=choretracker; DB_PASS=choretracker; DB_NAME=choretracker_test
export DB_HOST=127.0.0.1 DB_PORT=3306

say() { printf '\n== %s\n' "$*"; }
SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"

say "PHP"
if command -v php >/dev/null; then php -v | head -1; else
  $SUDO apt-get update -qq && $SUDO apt-get install -y -qq php-cli php-mysql php-mbstring php-xml php-curl unzip
fi
echo "(Production runs PHP 8.4; any 8.3+ is fine for tests.)"

say "Database"
DB_KIND=""
if command -v docker >/dev/null && docker info >/dev/null 2>&1; then
  if ! docker ps -a --format '{{.Names}}' | grep -qx choretracker-mysql57; then
    docker run -d --name choretracker-mysql57 -p 3306:3306 \
      -e MYSQL_ROOT_PASSWORD=root -e MYSQL_USER=$DB_USER -e MYSQL_PASSWORD=$DB_PASS \
      mysql:5.7 --character-set-server=utf8mb4 >/dev/null
  else
    docker start choretracker-mysql57 >/dev/null
  fi
  echo "Waiting for MySQL 5.7 in Docker..."
  for i in $(seq 1 60); do docker exec choretracker-mysql57 mysqladmin ping -uroot -proot --silent >/dev/null 2>&1 && break; sleep 2; done
  docker exec choretracker-mysql57 mysql -uroot -proot -e \
    "CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`${DB_NAME}\`.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
  DB_KIND="MySQL 5.7 (Docker)"
else
  echo "Docker isn't available here; using MariaDB (SQL in this repo is kept MySQL 5.7-compatible)."
  command -v mysqld >/dev/null || command -v mariadbd >/dev/null || { $SUDO apt-get update -qq && DEBIAN_FRONTEND=noninteractive $SUDO apt-get install -y -qq mariadb-server; }
  if ! mysqladmin ping --silent >/dev/null 2>&1; then
    $SUDO service mariadb start >/dev/null 2>&1 || $SUDO service mysql start >/dev/null 2>&1 || { ($SUDO mysqld_safe >/dev/null 2>&1 &); }
    for i in $(seq 1 30); do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
  fi
  $SUDO mysql -uroot -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS'; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`${DB_NAME}\`.* TO '$DB_USER'@'127.0.0.1'; GRANT ALL ON \`${DB_NAME}\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
  DB_KIND="$(mysql -uroot -N -e 'SELECT VERSION()' 2>/dev/null)"
fi
echo "Database ready: $DB_KIND"

say "PHPUnit (dev only)"
if command -v composer >/dev/null; then composer install --no-interaction --prefer-dist; else echo "Composer not found; install it, then run: composer install"; fi

say "Done. Run: scripts/test.sh"
