<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\Authorization\AccessPolicy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

abstract class ActorVoter extends Voter
{
    public function __construct(protected readonly AccessPolicy $policy)
    {
    }

    protected function actorId(TokenInterface $token): ?int
    {
        $user = $token->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
