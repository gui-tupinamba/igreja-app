<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Event;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class EventVoter extends ActorVoter
{
    public const EVENT_VIEW = 'EVENT_VIEW';
    public const EVENT_MANAGE = 'EVENT_MANAGE';
    public const EVENT_CREATE = 'EVENT_CREATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::EVENT_VIEW, self::EVENT_MANAGE => $subject instanceof Event,
            self::EVENT_CREATE => $subject === Event::class || $subject instanceof Event,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        if ($actorId === null) {
            return false;
        }
        if ($attribute === self::EVENT_CREATE) {
            if ($subject instanceof Event && ($subject->getId() !== null || ($subject->getMinistry() !== null && $subject->getMinistry()->getId() === null))) {
                return false;
            }

            return $this->policy->canManageContent($actorId, $subject instanceof Event ? $subject->getMinistry()?->getId() : null);
        }
        $id = $subject->getId();

        return $id !== null && match ($attribute) {
            self::EVENT_VIEW => $this->policy->canReadEvent($actorId, $id),
            self::EVENT_MANAGE => $this->policy->canManageEvent($actorId, $id),
            default => false,
        };
    }
}
