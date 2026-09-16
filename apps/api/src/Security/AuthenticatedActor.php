<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class AuthenticatedActor
{
    public function __construct(public int $userId, public string $sessionId)
    {
    }
}
