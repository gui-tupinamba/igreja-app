<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Only the digest is mapped. Raw refresh credentials never enter an entity. */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_refresh_tokens_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_refresh_tokens_replacement', columns: ['replaced_by_id'])]
#[ORM\UniqueConstraint(name: 'uniq_refresh_tokens_current_session', columns: ['session_id'], options: ['where' => '((consumed_at IS NULL) AND (revoked_at IS NULL))'])]
#[ORM\Index(name: 'idx_refresh_tokens_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_refresh_tokens_expires', columns: ['expires_at'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AuthSession::class)]
    #[ORM\JoinColumn(name: 'session_id', nullable: false, onDelete: 'RESTRICT')]
    private AuthSession $session;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $consumedAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replaced_by_id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $replacedBy;

    private function __construct()
    {
    }

    public function getId(): string { return $this->id; }
    public function getSession(): AuthSession { return $this->session; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function getConsumedAt(): ?DateTimeImmutable { return $this->consumedAt; }
    public function getRevokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function getReplacedBy(): ?self { return $this->replacedBy; }
}
