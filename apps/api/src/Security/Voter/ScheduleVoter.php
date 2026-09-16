<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\MinistrySchedule;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class ScheduleVoter extends ActorVoter
{
    public const SCHEDULE_VIEW = 'SCHEDULE_VIEW';
    public const SCHEDULE_MANAGE = 'SCHEDULE_MANAGE';
    public const SCHEDULE_CREATE = 'SCHEDULE_CREATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::SCHEDULE_VIEW, self::SCHEDULE_MANAGE => $subject instanceof MinistrySchedule,
            self::SCHEDULE_CREATE => $subject === MinistrySchedule::class || $subject instanceof MinistrySchedule,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        if ($actorId === null) {
            return false;
        }
        if ($attribute === self::SCHEDULE_CREATE) {
            if ($subject instanceof MinistrySchedule && ($subject->getId() !== null || ($subject->getMinistry() !== null && $subject->getMinistry()->getId() === null))) {
                return false;
            }

            return $this->policy->canManageContent($actorId, $subject instanceof MinistrySchedule ? $subject->getMinistry()?->getId() : null);
        }
        $id = $subject->getId();

        return $id !== null && match ($attribute) {
            self::SCHEDULE_VIEW => $this->policy->canReadSchedule($actorId, $id),
            self::SCHEDULE_MANAGE => $this->policy->canManageSchedule($actorId, $id),
            default => false,
        };
    }
}
