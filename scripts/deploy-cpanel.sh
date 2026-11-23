#!/usr/bin/env bash
# Run by .cpanel.yml when you click "Deploy HEAD Commit" in cPanel's Git Version Control.
# Copies public files into the site's web folder and everything else to a private folder OUTSIDE
# the web root. It never touches config.php.
#
# Folders come from ~/.choretracker-deploy.conf (create it once; see SETUP.md), e.g.:
#   WEB_DIR="$HOME/public_html/chore-test"        # the subdomain's document root
#   APP_DIR="$HOME/choretracker-test"             # private; defaults to this if not set
set -euo pipefail

CONF="$HOME/.choretracker-deploy.conf"
if [ ! -f "$CONF" ]; then
  echo "Missing $CONF. See SETUP.md ('Tell the deploy where your site lives')." >&2
  exit 1
fi
# shellcheck disable=SC1090
. "$CONF"
: "${WEB_DIR:?WEB_DIR is not set in $CONF}"
APP_DIR="${APP_DIR:-$HOME/choretracker-test}"

SRC="$(cd "$(dirname "$0")/.." && pwd)"

# Safety: refuse obviously wrong targets.
case "$WEB_DIR" in "$HOME"|"$HOME/"|"/"|"") echo "WEB_DIR looks wrong: $WEB_DIR" >&2; exit 1;; esac
[ "$WEB_DIR" != "$APP_DIR" ] || { echo "WEB_DIR and APP_DIR must be different folders." >&2; exit 1; }

mkdir -p "$WEB_DIR" "$APP_DIR"

# Private app code: replace these folders wholesale (config.php lives beside them and is left alone).
for d in api db scripts; do
  rm -rf "${APP_DIR:?}/$d"
  cp -R "$SRC/$d" "$APP_DIR/$d"
done
cp "$SRC/config.example.php" "$APP_DIR/config.example.php"

# Public files: refresh the folders we own, then copy over the top.
# IMPORTANT: never overwrite an existing top-level .htaccess. cPanel's MultiPHP Manager writes the PHP
# version into that file; replacing it would silently drop the site back to an older PHP.
KEEP_HTACCESS=""
if [ -f "$WEB_DIR/.htaccess" ]; then
  KEEP_HTACCESS="$(mktemp)"
  cp -p "$WEB_DIR/.htaccess" "$KEEP_HTACCESS"
fi
rm -rf "${WEB_DIR:?}/js" "${WEB_DIR:?}/css" "${WEB_DIR:?}/api"
cp -R "$SRC/web/." "$WEB_DIR/"
if [ -n "$KEEP_HTACCESS" ]; then
  cp -p "$KEEP_HTACCESS" "$WEB_DIR/.htaccess"
  rm -f "$KEEP_HTACCESS"
fi

# Tell the public API entry point where the private code is.
printf "<?php\nreturn %s;\n" "'$APP_DIR'" > "$WEB_DIR/api/app-dir.php"

echo "Deployed. Web: $WEB_DIR   App: $APP_DIR"
if [ ! -f "$APP_DIR/config.php" ]; then
  echo "NOTE: $APP_DIR/config.php does not exist yet. Create it from config.example.php (see SETUP.md)."
fi
echo "If this update changed the database, run migrations: php $APP_DIR/scripts/migrate.php"
