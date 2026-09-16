<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root.'/.env';

if (file_exists($target)) {
    fwrite(STDOUT, ".env já existe; nenhum valor foi alterado.\n");
    exit(0);
}

$template = file_get_contents($root.'/.env.example');
if ($template === false) {
    fwrite(STDERR, "Não foi possível ler .env.example.\n");
    exit(1);
}

foreach (['APP_SECRET', 'POSTGRES_PASSWORD', 'APP_DATABASE_PASSWORD'] as $name) {
    $template = str_replace($name."=\n", $name.'='.bin2hex(random_bytes(32))."\n", str_replace("\r\n", "\n", $template));
}

umask(0077);
$file = fopen($target, 'x');
if ($file === false) {
    fwrite(STDERR, "Não foi possível criar .env; verifique as permissões.\n");
    exit(1);
}

$written = fwrite($file, $template);
fclose($file);
if ($written !== strlen($template)) {
    fwrite(STDERR, "Gravação incompleta de .env; verifique o arquivo antes de executar o Compose.\n");
    exit(1);
}

fwrite(STDOUT, ".env criado com segredos aleatórios locais. Os valores não foram exibidos.\n");
