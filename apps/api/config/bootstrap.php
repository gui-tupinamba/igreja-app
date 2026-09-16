<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'prod';
$_SERVER['APP_DEBUG'] ??= $_ENV['APP_DEBUG'] ?? '0';
