<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use DateTimeImmutable;

/** Internal result; controllers explicitly construct the transport-specific JSON. */
final readonly class AuthResult
{
    public function __construct(
        public User $user,
        public string $sessionId,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
