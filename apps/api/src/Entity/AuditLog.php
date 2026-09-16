<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only through transactional application services; never serialize users here. */
#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_logs_entity_created', columns: ['entity_type', 'entity_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_audit_logs_actor_created', columns: ['actor_id', 'created_at', 'id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\Column(name: 'entity_type', length: 40)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: Types::INTEGER)]
    private int $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $metadata;

    #[ORM\Column(name: 'request_id', length: 32, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getId(): ?int { return $this->id; }
    public function getActor(): ?User { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): int { return $this->entityId; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
