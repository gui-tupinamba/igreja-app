<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Guard;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\CommentStatus;
use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'idx_comments_post', columns: ['post_id', 'status', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_comments_user', columns: ['user_id'])]
class Comment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'RESTRICT')]
    private Post $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(length: 16, enumType: CommentStatus::class)]
    private CommentStatus $status = CommentStatus::VISIBLE;

    public function __construct(Post $post, User $user, string $content)
    {
        $this->content = Guard::text($content, 5000);
        $this->post = $post;
        $this->user = $user;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatus(): CommentStatus
    {
        return $this->status;
    }

    public function editContent(string $content): void
    {
        $this->content = Guard::text($content, 5000);
        $this->touch();
    }

    public function hide(): void
    {
        $this->status = CommentStatus::HIDDEN;
        $this->touch();
    }

    public function delete(): void
    {
        $this->status = CommentStatus::DELETED;
        $this->touch();
    }
}
