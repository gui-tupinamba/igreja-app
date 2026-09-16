<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MembershipStatus;
use App\Enum\MinistryStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserMinistryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(repositoryClass: UserMinistryRepository::class)]
#[ORM\Table(name: 'user_ministries')]
#[ORM\UniqueConstraint(name: 'uniq_user_ministries_pair', columns: ['user_id', 'ministry_id'])]
#[ORM\Index(name: 'idx_user_ministries_user_status_ministry', columns: ['user_id', 'status', 'ministry_id'])]
#[ORM\Index(name: 'idx_user_ministries_ministry_status_leader_user', columns: ['ministry_id', 'status', 'is_leader', 'user_id'])]
class UserMinistry
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Ministry::class)]
    #[ORM\JoinColumn(name: 'ministry_id', nullable: false, onDelete: 'RESTRICT')]
    private Ministry $ministry;

    #[ORM\Column(name: 'is_leader', type: Types::BOOLEAN)]
    private bool $isLeader = false;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::ACTIVE;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $joinedAt;

    #[ORM\Column(name: 'left_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $leftAt = null;

    public function __construct(User $user, Ministry $ministry)
    {
        $this->user = $user;
        $this->ministry = $ministry;
        $this->initializeTimestamps();
        $this->joinedAt = $this->getCreatedAt();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMinistry(): Ministry
    {
        return $this->ministry;
    }

    public function isLeader(): bool
    {
        return $this->isLeader;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function getJoinedAt(): DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function promoteToLeader(): void
    {
        if ($this->status !== MembershipStatus::ACTIVE
            || $this->user->getStatus() !== UserStatus::ACTIVE
            || $this->ministry->getStatus() !== MinistryStatus::ACTIVE
            || $this->user->getRole() === UserRole::MEMBER) {
            throw new DomainException('Leadership requires an active membership, user and ministry, and an eligible global role.');
        }

        $this->isLeader = true;
        $this->touch();
    }

    public function demoteLeadership(): void
    {
        $this->isLeader = false;
        $this->touch();
    }

    public function deactivate(): void
    {
        if ($this->status === MembershipStatus::INACTIVE) {
            return;
        }

        $this->status = MembershipStatus::INACTIVE;
        $this->isLeader = false;
        $this->touch();
        $this->leftAt = $this->getUpdatedAt();
    }

    public function reactivate(): void
    {
        if ($this->status === MembershipStatus::ACTIVE) {
            return;
        }

        $this->status = MembershipStatus::ACTIVE;
        $this->isLeader = false;
        $this->leftAt = null;
        $this->touch();
        $this->joinedAt = $this->getUpdatedAt();
    }
}
