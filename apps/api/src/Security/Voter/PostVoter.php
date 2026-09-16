<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Post;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class PostVoter extends ActorVoter
{
    public const POST_VIEW = 'POST_VIEW';
    public const POST_MANAGE = 'POST_MANAGE';
    public const POST_CREATE = 'POST_CREATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::POST_VIEW, self::POST_MANAGE => $subject instanceof Post,
            self::POST_CREATE => $subject === Post::class || $subject instanceof Post,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        if ($actorId === null) {
            return false;
        }
        if ($attribute === self::POST_CREATE) {
            if ($subject instanceof Post && ($subject->getId() !== null || ($subject->getMinistry() !== null && $subject->getMinistry()->getId() === null))) {
                return false;
            }

            return $this->policy->canManageContent($actorId, $subject instanceof Post ? $subject->getMinistry()?->getId() : null);
        }
        $id = $subject->getId();

        return $id !== null && match ($attribute) {
            self::POST_VIEW => $this->policy->canReadPost($actorId, $id),
            self::POST_MANAGE => $this->policy->canManagePost($actorId, $id),
            default => false,
        };
    }
}
