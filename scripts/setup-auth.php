<?php

declare(strict_types=1);

// Kept separate so upgrading authentication never overwrites an existing .env.
$directory = dirname(__DIR__).'/.secrets';
$privatePath = $directory.'/jwt-private.pem';
$publicPath = $directory.'/jwt-public.pem';
if (file_exists($privatePath) || file_exists($publicPath)) {
    if (!is_file($privatePath) || !is_file($publicPath)) {
        fwrite(STDERR, "Par de chaves incompleto; restaure ambas antes de continuar.\n");
        exit(1);
    }
    $private = openssl_pkey_get_private(file_get_contents($privatePath));
    $public = openssl_pkey_get_public(file_get_contents($publicPath));
    if ($private === false || $public === false
        || openssl_pkey_get_details($private)['key'] !== openssl_pkey_get_details($public)['key']) {
        fwrite(STDERR, "O par de chaves existente não é válido. Nenhum arquivo foi alterado.\n");
        exit(1);
    }
    fwrite(STDOUT, "Chaves JWT existentes preservadas e verificadas.\n");
    exit(0);
}

umask(0077);
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Não foi possível criar o diretório de chaves.');
}
// Directory is private on Unix; individual bind mounts must be readable by PHP-FPM.
chmod($directory, 0700);
$options = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF') && is_file('C:/xampp/apache/conf/openssl.cnf')) {
    $options['config'] = 'C:/xampp/apache/conf/openssl.cnf';
}
$key = openssl_pkey_new($options);
if ($key === false || !openssl_pkey_export($key, $privatePem, null, $options)) {
    throw new RuntimeException('Não foi possível gerar chaves RSA. Verifique o OpenSSL.');
}
$publicPem = openssl_pkey_get_details($key)['key'];
foreach ([$privatePath => $privatePem, $publicPath => $publicPem] as $path => $contents) {
    $file = fopen($path, 'x');
    if ($file === false || fwrite($file, $contents) !== strlen($contents)) {
        throw new RuntimeException('Falha ao gravar o par de chaves.');
    }
    fclose($file);
    chmod($path, 0644);
}
fwrite(STDOUT, "Chaves JWT criadas em .secrets, fora do Git e da imagem Docker. Nenhum segredo foi exibido.\n");
