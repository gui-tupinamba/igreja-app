<?php

declare(strict_types=1);

if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
    throw new RuntimeException('Test keys require APP_ENV=test.');
}
$directory = dirname(__DIR__).'/var/test-keys';
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$lock = fopen($directory.'/generate.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Could not lock test key generation.');
}
try {
    if (!is_file($directory.'/private.pem') || !is_file($directory.'/public.pem')) {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF') && is_file('C:/xampp/apache/conf/openssl.cnf')) {
            $options['config'] = 'C:/xampp/apache/conf/openssl.cnf';
        }
        $key = openssl_pkey_new($options);
        if ($key === false || !openssl_pkey_export($key, $private, null, $options)) {
            throw new RuntimeException('Could not generate test keys.');
        }
        file_put_contents($directory.'/private.pem', $private);
        file_put_contents($directory.'/public.pem', openssl_pkey_get_details($key)['key']);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
foreach ([
    'JWT_PRIVATE_KEY_PATH' => $directory.'/private.pem',
    'JWT_PUBLIC_KEY_PATH' => $directory.'/public.pem',
    'JWT_ISSUER' => 'igreja-api-test',
    'JWT_AUDIENCE' => 'igreja-clients-test',
    'AUTH_ALLOWED_ORIGINS'='http://localhost:5173,http://127.0.0.1:5173,https://www.guitupinamba.dev,https://guitupinamba.dev',
    'AUTH_ALLOW_INSECURE_LOCAL' => '0',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}
