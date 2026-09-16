<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Only fixed field names and messages can reach the response. */
#[Exclude]
final class InputValidationException extends UnprocessableEntityHttpException
{
    public readonly array $fields;

    public function __construct(string $field, string $reason)
    {
        if (!in_array($field, ['body', 'name', 'email', 'password', 'new_password', 'current_password', 'phone', 'birth_date', 'role', 'status', 'slug', 'description', 'user_id', 'promote_to_leader', 'title', 'content', 'ministry_id', 'visibility', 'comments_enabled', 'q', 'location', 'address', 'starts_at', 'ends_at', 'from', 'to'], true)) {
            throw new \InvalidArgumentException('Unknown validation field.');
        }
        $message = match ($reason) {
            'name' => 'Informe um nome com até 120 caracteres.',
            'email' => 'Informe um e-mail válido com até 180 caracteres.',
            'password' => 'A senha deve ter entre 12 e 72 bytes, sem caracteres nulos ou apenas espaços.',
            'current_password' => 'Informe a senha atual.',
            'phone' => 'Informe um telefone com até 30 caracteres ou null para removê-lo.',
            'birth_date' => 'Informe uma data válida no formato AAAA-MM-DD, que não esteja no futuro, ou null.',
            'role' => 'Cargo inválido. Use ADMIN, PASTOR, LEADER ou MEMBER.',
            'status' => 'Status inválido. Use ACTIVE, INACTIVE ou BLOCKED.',
            'body' => 'Envie ao menos um campo permitido para esta operação.',
            'slug' => 'Use até 160 letras minúsculas, números e hífens simples.',
            'description' => 'Informe uma descrição com até 10.000 caracteres ou null.',
            'ministry_status' => 'Status inválido. Use ACTIVE ou INACTIVE.',
            'user_id' => 'Informe um ID de usuário inteiro positivo.',
            'promote_to_leader' => 'Informe true ou false para a promoção explícita de cargo.',
            'title' => 'Informe um título com até 180 caracteres.',
            'content' => 'Informe um conteúdo com até 50.000 caracteres.',
            'comment_content' => 'Informe um comentário com até 5.000 caracteres.',
            'comment_status' => 'Use VISIBLE, HIDDEN ou DELETED.',
            'ministry_id' => 'Informe um ID de ministério inteiro positivo ou null para conteúdo geral.',
            'private_ministry' => 'Conteúdo privado exige um ministério.',
            'visibility' => 'Use PUBLIC ou MINISTRY_MEMBERS.',
            'comments_enabled' => 'Informe true ou false para permitir comentários.',
            'post_status' => 'Use DRAFT, PUBLISHED ou ARCHIVED.',
            'event_status' => 'Use um estado permitido nesta consulta.',
            'event_description' => 'Informe uma descrição com até 50.000 caracteres ou null.',
            'location' => 'Informe um local com até 180 caracteres ou null.',
            'address' => 'Informe um endereço com até 500 caracteres ou null.',
            'datetime' => 'Informe data e hora válidas, com segundos e fuso: AAAA-MM-DDTHH:MM:SSZ ou offset ±HH:MM.',
            'interval' => 'O fim não pode ser anterior ao início.',
            'period' => 'O limite final da consulta deve ser posterior ao inicial.',
            'q' => 'Informe uma busca com até 120 caracteres.',
            default => throw new \InvalidArgumentException('Unknown validation reason.'),
        };
        $this->fields = [$field => $message];
        parent::__construct();
    }
}
