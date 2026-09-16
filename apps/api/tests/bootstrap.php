<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

date_default_timezone_set('UTC');

require __DIR__.'/generate-keys.php';

// Functional tests replace readiness with a double and must not require a database.
if (!isset($_ENV['DATABASE_URL']) && getenv('DATABASE_URL') === false) {
    $_ENV['DATABASE_URL'] = 'postgresql://test:test@127.0.0.1:1/test?serverVersion=17&charset=utf8';
}
