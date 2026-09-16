#!/bin/sh
set -eu

if [ "${1:-}" = 'php-fpm' ]; then
    php bin/console cache:warmup --no-debug
fi

exec docker-php-entrypoint "$@"
