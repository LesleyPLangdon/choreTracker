#!/usr/bin/env bash
# Optional local preview: http://localhost:8080  (needs config.php; see config.example.php)
cd "$(dirname "$0")/.."
exec php -S 127.0.0.1:${PORT:-8080} -t web scripts/dev-router.php
