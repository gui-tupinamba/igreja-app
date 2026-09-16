<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Guard;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\ContentVisibility;
use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_upcoming', columns: ['status', 'starts_at', 'id'])]
#[ORM\Index(name: 'idx_events_ministry_upcoming', columns: ['ministry_id', 'status', 'starts_at', 'id'])]
#[ORM\Index(name: 'idx_events_creator', columns: ['created_by'])]
class Event
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: Ministry::class)]
    #[ORM\JoinColumn(name: 'ministry_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Ministry $ministry;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, precision: 0)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, precision: 0, nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column(length: 20, enumType: ContentVisibility::class)]
    private ContentVisibility $visibility;

    #[ORM\Column(length: 16, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::DRAFT;

    public function __construct(
        User $createdBy,
        string $title,
        \DateTimeImmutable $startsAt,
        ?\DateTimeImmutable $endsAt = null,
        ?Ministry $ministry = null,
        ContentVisibility $visibility = ContentVisibility::PUBLIC,
    ) {
        self::requireMinistry($ministry, $visibility);
        self::requireValidInterval($startsAt, $endsAt);
        $this->title = Guard::text($title, 180);
        $this->createdBy = $createdBy;
        $this->startsAt = Guard::utc($startsAt);
        $this->endsAt = $endsAt === null ? null : Guard::utc($endsAt);
        $this->ministry = $ministry;
        $this->visibility = $visibility;
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getMinistry(): ?Ministry
    {
        return $this->ministry;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getVisibility(): ContentVisibility
    {
        return $this->visibility;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function setDetails(?string $description, ?string $location, ?string $address): void
    {
        $description = Guard::optionalText($description, 50000);
        $location = Guard::optionalText($location, 180);
        $address = Guard::optionalText($address, 500);
        $this->description = $description;
        $this->location = $location;
        $this->address = $address;
        $this->touch();
    }

    public function reschedule(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt = null): void
    {
        self::requireValidInterval($startsAt, $endsAt);
        $this->startsAt = Guard::utc($startsAt);
        $this->endsAt = $endsAt === null ? null : Guard::utc($endsAt);
        $this->touch();
    }

    public function changeAudience(?Ministry $ministry, ContentVisibility $visibility): void
    {
        self::requireMinistry($ministry, $visibility);
        $this->ministry = $ministry;
        $this->visibility = $visibility;
        $this->touch();
    }

    public function publish(): void
    {
        $this->status = EventStatus::PUBLISHED;
        $this->touch();
    }

    public function cancel(): void
    {
        $this->status = EventStatus::CANCELLED;
        $this->touch();
    }

    public function archive(): void
    {
        $this->status = EventStatus::ARCHIVED;
        $this->touch();
    }

    private static function requireMinistry(?Ministry $ministry, ContentVisibility $visibility): void
    {
        if ($visibility === ContentVisibility::MINISTRY_MEMBERS && $ministry === null) {
            throw new \InvalidArgumentException('Conteúdo privado exige um ministério.');
        }
    }

    private static function requireValidInterval(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): void
    {
        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new \InvalidArgumentException('O fim não pode ser anterior ao início.');
        }
    }
}
