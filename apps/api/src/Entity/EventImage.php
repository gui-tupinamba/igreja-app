<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'event_images')]
#[ORM\Index(
    name: 'idx_event_images_event_position',
    columns: ['event_id', 'position', 'id']
)]
#[ORM\UniqueConstraint(
    name: 'uniq_event_images_event_position',
    columns: ['event_id', 'position']
)]
class EventImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(name: 'storage_name', length: 255)]
    private string $storageName;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'mime_type', length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, precision: 0)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Event $event,
        string $storageName,
        string $originalName,
        string $mimeType,
        int $size,
        int $position = 0,
    ) {
        if ($size <= 0 || $position < 0) {
            throw new \InvalidArgumentException('Tamanho e posição da imagem devem ser válidos.');
        }

        $this->event = $event;
        $this->storageName = $storageName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): Event { return $this->event; }
    public function getStorageName(): string { return $this->storageName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getPosition(): int { return $this->position; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
