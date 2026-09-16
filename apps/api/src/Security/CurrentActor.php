<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class CurrentActor
{
    public function __construct(private TokenStorageInterface $tokens, private RequestStack $requests)
    {
    }

    public function get(): AuthenticatedActor
    {
        $user = $this->tokens->getToken()?->getUser();
        $sessionId = $this->requests->getCurrentRequest()?->attributes->get('_auth_session_id');
        if (!$user instanceof User || $user->getId() === null || !is_string($sessionId)) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return new AuthenticatedActor($user->getId(), $sessionId);
    }
}
