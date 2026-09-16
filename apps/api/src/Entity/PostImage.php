<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'post_images')]
#[ORM\Index(
    name: 'idx_post_images_post_position',
    columns: ['post_id', 'position', 'id']
)]
class PostImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(
        name: 'post_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private Post $post;

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

    #[ORM\Column(
        name: 'created_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        precision: 0
    )]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Post $post,
        string $storageName,
        string $originalName,
        string $mimeType,
        int $size,
        int $position = 0,
    ) {
        if ($size <= 0) {
            throw new \InvalidArgumentException(
                'O tamanho da imagem deve ser maior que zero.'
            );
        }

        if ($position < 0) {
            throw new \InvalidArgumentException(
                'A posição não pode ser negativa.'
            );
        }

        $this->post = $post;
        $this->storageName = $storageName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getStorageName(): string
    {
        return $this->storageName;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setPosition(int $position): void
    {
        if ($position < 0) {
            throw new \InvalidArgumentException(
                'A posição não pode ser negativa.'
            );
        }

        $this->position = $position;
    }
}