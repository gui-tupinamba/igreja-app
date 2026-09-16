<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Durable fixed-window counters. The identifier contains no plaintext email/IP. */
#[ORM\Entity]
#[ORM\Table(name: 'auth_login_limits')]
#[ORM\Index(name: 'idx_auth_login_limits_window', columns: ['window_started_at'])]
class AuthLoginLimit
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts;

    #[ORM\Column(name: 'window_started_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $windowStartedAt;

    private function __construct()
    {
    }

    public function getId(): string { return $this->id; }
    public function getAttempts(): int { return $this->attempts; }
    public function getWindowStartedAt(): DateTimeImmutable { return $this->windowStartedAt; }
}
