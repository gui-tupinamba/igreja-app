<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\Post;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class CommentVoter extends ActorVoter
{
    public const COMMENT_VIEW = 'COMMENT_VIEW';
    public const COMMENT_CREATE = 'COMMENT_CREATE';
    public const COMMENT_EDIT = 'COMMENT_EDIT';
    public const COMMENT_MODERATE = 'COMMENT_MODERATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::COMMENT_CREATE => $subject instanceof Post,
            self::COMMENT_VIEW, self::COMMENT_EDIT, self::COMMENT_MODERATE => $subject instanceof Comment,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $actorId = $this->actorId($token);
        $id = $subject->getId();
        if ($actorId === null || $id === null) {
            return false;
        }

        return match ($attribute) {
            self::COMMENT_CREATE => $this->policy->canCommentOnPost($actorId, $id),
            self::COMMENT_VIEW => $this->policy->canReadComment($actorId, $id),
            self::COMMENT_EDIT => $this->policy->canEditComment($actorId, $id),
            self::COMMENT_MODERATE => $this->policy->canModerateComment($actorId, $id),
            default => false,
        };
    }
}
