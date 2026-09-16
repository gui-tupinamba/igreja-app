#!/bin/sh
set -eu

export JWT_PRIVATE_KEY_PATH=/var/www/api/var/test-keys/private.pem
export JWT_PUBLIC_KEY_PATH=/var/www/api/var/test-keys/public.pem
php tests/generate-keys.php

if [ "${RUN_DATABASE_TESTS:-0}" = '1' ]; then
    if [ "${APP_ENV:-}" != 'test' ]; then
        echo 'Integration tests require APP_ENV=test.' >&2
        exit 1
    fi
    case "${DATABASE_URL:-}" in
        postgresql://*@postgres-test:5432/igreja_test\?*) ;;
        *)
            echo 'Integration tests require the isolated postgres-test database.' >&2
            exit 1
            ;;
    esac
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console doctrine:schema:validate
fi

php bin/console cache:warmup --no-debug
exec php vendor/bin/phpunit "$@"
