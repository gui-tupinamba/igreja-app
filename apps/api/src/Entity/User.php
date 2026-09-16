<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Guard;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email_normalized', columns: ['email_normalized'])]
#[ORM\Index(name: 'idx_users_status_created_id', columns: ['status', 'created_at', 'id'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'email_normalized', length: 180)]
    private string $emailNormalized;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'birth_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 20, enumType: UserRole::class)]
    private UserRole $role = UserRole::MEMBER;

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::ACTIVE;

    public function __construct(string $name, string $email, string $passwordHash)
    {
        $this->name = Guard::text($name, 120);
        $this->assignEmail($email);
        $this->assignPasswordHash($passwordHash);
        $this->initializeTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = Guard::text($name, 120);
        $this->touch();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getEmailNormalized(): string
    {
        return $this->emailNormalized;
    }

    public function setEmail(string $email): void
    {
        $this->assignEmail($email);
        $this->touch();
    }

    private function assignEmail(string $email): void
    {
        $email = Guard::text($email, 180);

        if (preg_match('/[^\x00-\x7f]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid ASCII email address is required.');
        }

        $this->email = $email;
        $this->emailNormalized = strtolower($email);
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER', 'ROLE_'.$this->role->value];
    }

    public function eraseCredentials(): void
    {
        // Plaintext credentials are never stored on this entity.
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->assignPasswordHash($passwordHash);
        $this->touch();
    }

    private function assignPasswordHash(string $passwordHash): void
    {
        if (strlen($passwordHash) > 255 || password_get_info($passwordHash)['algo'] === null) {
            throw new InvalidArgumentException('A recognized password hash is required.');
        }

        $this->passwordHash = $passwordHash;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = Guard::optionalText($phone, 30);
        $this->touch();
    }

    public function getBirthDate(): ?DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?DateTimeImmutable $birthDate): void
    {
        $this->birthDate = $birthDate === null
            ? null
            : new DateTimeImmutable($birthDate->format('Y-m-d'), new DateTimeZone('UTC'));
        $this->touch();
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    // Authorization and revocation of existing leadership belong to transactional services.
    public function setRole(UserRole $role): void
    {
        $this->role = $role;
        $this->touch();
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }
}
