<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Guard;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\ContentVisibility;
use App\Enum\PostStatus;
use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(name: 'idx_posts_feed', columns: ['status', 'published_at', 'id'])]
#[ORM\Index(name: 'idx_posts_ministry_feed', columns: ['ministry_id', 'status', 'published_at', 'id'])]
#[ORM\Index(name: 'idx_posts_author', columns: ['author_id'])]
class Post
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: Ministry::class)]
    #[ORM\JoinColumn(name: 'ministry_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Ministry $ministry;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(length: 20, enumType: ContentVisibility::class)]
    private ContentVisibility $visibility;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $commentsEnabled = true;

    #[ORM\Column(length: 16, enumType: PostStatus::class)]
    private PostStatus $status = PostStatus::DRAFT;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, precision: 0, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(
        User $author,
        string $title,
        string $content,
        ?Ministry $ministry = null,
        ContentVisibility $visibility = ContentVisibility::PUBLIC,
    ) {
        self::requireMinistry($ministry, $visibility);
        $this->title = Guard::text($title, 180);
        $this->content = Guard::text($content, 50000);
        $this->author = $author;
        $this->ministry = $ministry;
        $this->visibility = $visibility;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getMinistry(): ?Ministry
    {
        return $this->ministry;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getVisibility(): ContentVisibility
    {
        return $this->visibility;
    }

    public function getStatus(): PostStatus
    {
        return $this->status;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function updateContent(string $title, string $content): void
    {
        $title = Guard::text($title, 180);
        $content = Guard::text($content, 50000);
        $this->title = $title;
        $this->content = $content;
        $this->touch();
    }

    public function changeAudience(?Ministry $ministry, ContentVisibility $visibility): void
    {
        self::requireMinistry($ministry, $visibility);
        $this->ministry = $ministry;
        $this->visibility = $visibility;
        $this->touch();
    }

    public function setCommentsEnabled(bool $enabled): void
    {
        $this->commentsEnabled = $enabled;
        $this->touch();
    }

    public function publish(?\DateTimeImmutable $publishedAt = null): void
    {
        $this->publishedAt = Guard::utc($publishedAt ?? $this->publishedAt ?? new \DateTimeImmutable('@'.time()));
        $this->status = PostStatus::PUBLISHED;
        $this->touch();
    }

    public function archive(): void
    {
        $this->status = PostStatus::ARCHIVED;
        $this->touch();
    }

    private static function requireMinistry(?Ministry $ministry, ContentVisibility $visibility): void
    {
        if ($visibility === ContentVisibility::MINISTRY_MEMBERS && $ministry === null) {
            throw new \InvalidArgumentException('Conteúdo privado exige um ministério.');
        }
    }
}
