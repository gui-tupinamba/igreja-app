<?php

declare(strict_types=1);

namespace App\Auth\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Only these fixed messages may be shown by the bootstrap console. */
#[Exclude]
final class AdminCreationException extends \DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'name' => 'Informe um nome com 1 a 120 caracteres válidos.',
            'email' => 'Informe um email válido, sem acentos e com até 180 caracteres.',
            'password_short' => 'A senha precisa ter pelo menos 12 bytes (12 caracteres sem acentos).',
            'password_long' => 'A senha pode ter no máximo 72 bytes; caracteres acentuados ocupam mais de um byte.',
            'password_invalid' => 'A senha não pode conter apenas espaços nem caracteres nulos.',
            'admin_exists' => 'Já existe um administrador. Este comando cria somente o primeiro ADMIN.',
            'email_taken' => 'Este email já está cadastrado. O comando não promove contas existentes.',
            default => throw new \InvalidArgumentException('Unknown administrator creation error.'),
        });
    }
}
