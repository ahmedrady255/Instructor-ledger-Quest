#!/usr/bin/env bash
set -euo pipefail

if [ -d "/var/www/Instructor-ledger/storage" ]; then
    mkdir -p /var/www/Instructor-ledger/storage/framework/{sessions,views,cache/data} /var/www/Instructor-ledger/storage/logs /var/www/Instructor-ledger/bootstrap/cache
    chown -R www-data:www-data /var/www/Instructor-ledger/storage /var/www/Instructor-ledger/bootstrap/cache 2>/dev/null || true
    chmod -R 775 /var/www/Instructor-ledger/storage /var/www/Instructor-ledger/bootstrap/cache 2>/dev/null || true
fi

exec "$@"
