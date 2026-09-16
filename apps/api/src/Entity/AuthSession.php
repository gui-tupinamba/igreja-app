<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthClientType;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** SessionService owns writes and the transaction/lock order for these records. */
#[ORM\Entity]
#[ORM\Table(name: 'auth_sessions')]
#[ORM\Index(name: 'idx_auth_sessions_user_revoked', columns: ['user_id', 'revoked_at'])]
#[ORM\Index(name: 'idx_auth_sessions_expires', columns: ['expires_at'])]
class AuthSession
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(name: 'client_type', length: 10, enumType: AuthClientType::class)]
    private AuthClientType $clientType;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $lastUsedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt;

    private function __construct()
    {
    }

    public function getId(): string { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getClientType(): AuthClientType { return $this->clientType; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): DateTimeImmutable { return $this->lastUsedAt; }
    public function getExpiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function getRevokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
}
