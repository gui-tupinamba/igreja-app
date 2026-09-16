<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Ministry;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class MinistryVoter extends ActorVoter
{
    public const MINISTRY_LIST = 'MINISTRY_LIST';
    public const MINISTRY_CREATE = 'MINISTRY_CREATE';
    public const MINISTRY_VIEW = 'MINISTRY_VIEW';
    public const MINISTRY_MANAGE = 'MINISTRY_MANAGE';
    public const MINISTRY_MANAGE_CONTENT = 'MINISTRY_MANAGE_CONTENT';
    public const MINISTRY_VIEW_MEMBERS = 'MINISTRY_VIEW_MEMBERS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MINISTRY_LIST, self::MINISTRY_CREATE => $subject === Ministry::class,
            self::MINISTRY_VIEW, self::MINISTRY_MANAGE, self::MINISTRY_MANAGE_CONTENT, self::MINISTRY_VIEW_MEMBERS => $subject instanceof Ministry,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        if ($actorId === null) {
            return false;
        }
        if ($subject === Ministry::class) {
            return $this->policy->canManageMinistries($actorId);
        }
        $id = $subject->getId();
        if ($id === null) {
            return false;
        }

        return match ($attribute) {
            self::MINISTRY_VIEW => $this->policy->canReadMinistry($actorId, $id),
            self::MINISTRY_MANAGE => $this->policy->canManageMinistries($actorId) && $this->policy->canManageContent($actorId, $id),
            self::MINISTRY_MANAGE_CONTENT, self::MINISTRY_VIEW_MEMBERS => $this->policy->canManageContent($actorId, $id),
            default => false,
        };
    }
}
