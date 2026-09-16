<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Guard;
use App\Entity\Traits\TimestampableTrait;
use App\Enum\MinistryStatus;
use App\Repository\MinistryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: MinistryRepository::class)]
#[ORM\Table(name: 'ministries')]
#[ORM\UniqueConstraint(name: 'uniq_ministries_slug', columns: ['slug'])]
class Ministry
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 20, enumType: MinistryStatus::class)]
    private MinistryStatus $status = MinistryStatus::ACTIVE;

    public function __construct(string $name, string $slug, ?string $description = null)
    {
        $this->name = Guard::text($name, 120);
        $this->assignSlug($slug);
        $this->description = Guard::optionalText($description, 10000);
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->assignSlug($slug);
        $this->touch();
    }

    private function assignSlug(string $slug): void
    {
        $slug = Guard::text($slug, 160);

        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new InvalidArgumentException('Slug must contain lowercase ASCII letters or digits separated by single hyphens.');
        }

        $this->slug = $slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = Guard::optionalText($description, 10000);
        $this->touch();
    }

    public function getStatus(): MinistryStatus
    {
        return $this->status;
    }

    public function setStatus(MinistryStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }
}
