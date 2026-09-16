<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class UserVoter extends ActorVoter
{
    public const USER_LIST = 'USER_LIST';
    public const USER_CREATE = 'USER_CREATE';
    public const USER_VIEW = 'USER_VIEW';
    public const USER_EDIT = 'USER_EDIT';
    public const USER_MANAGE = 'USER_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::USER_LIST, self::USER_CREATE => $subject === User::class,
            self::USER_VIEW, self::USER_EDIT, self::USER_MANAGE => $subject instanceof User,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        if ($actorId === null) {
            return false;
        }

        return $subject === User::class
            ? $this->policy->canManageUsers($actorId)
            : ($subject->getId() !== null && $this->policy->canManageUsers($actorId, $subject->getId()));
    }
}
