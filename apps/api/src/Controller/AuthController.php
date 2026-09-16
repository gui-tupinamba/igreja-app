<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AuthResult;
use App\Auth\AuthTransport;
use App\Auth\LoginThrottle;
use App\Auth\SessionService;
use App\Entity\User;
use App\Enum\AuthClientType;
use App\Security\JwtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsController]
final readonly class AuthController
{
    public function __construct(
        private AuthTransport $transport,
        private SessionService $sessions,
        private LoginThrottle $throttle,
        private JwtService $jwt,
    ) {
    }

    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'], defaults: ['auth_client' => 'WEB'])]
    #[Route('/api/auth/mobile/login', name: 'api_auth_mobile_login', methods: ['POST'], defaults: ['auth_client' => 'MOBILE'])]
    public function login(Request $request): JsonResponse
    {
        $clientType = $this->clientType($request);
        [$email, $password] = $this->transport->credentials($this->transport->input($request, $clientType));
        $this->throttle->consume($email, $request->getClientIp() ?? 'unknown');
        $result = $this->sessions->login($email, $password, $clientType);

        return $this->tokenResponse($request, $result, $clientType);
    }

    #[Route('/api/auth/refresh', name: 'api_auth_refresh', methods: ['POST'], defaults: ['auth_client' => 'WEB'])]
    #[Route('/api/auth/mobile/refresh', name: 'api_auth_mobile_refresh', methods: ['POST'], defaults: ['auth_client' => 'MOBILE'])]
    public function refresh(Request $request): JsonResponse
    {
        $clientType = $this->clientType($request);
        $data = $this->transport->input($request, $clientType);
        $rawToken = $this->transport->refreshToken($request, $clientType, $data);
        $result = $this->sessions->refresh($rawToken, $clientType);

        return $this->tokenResponse($request, $result, $clientType);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'], defaults: ['auth_client' => 'WEB'])]
    #[Route('/api/auth/mobile/logout', name: 'api_auth_mobile_logout', methods: ['POST'], defaults: ['auth_client' => 'MOBILE'])]
    public function logout(Request $request): Response
    {
        $clientType = $this->clientType($request);
        $data = $this->transport->input($request, $clientType);
        $rawToken = $this->transport->refreshToken($request, $clientType, $data, allowMissing: true);
        if ($rawToken !== null) {
            $this->sessions->logout($rawToken, $clientType);
        }
        $response = new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
        if ($clientType === AuthClientType::WEB) {
            $response->headers->setCookie($this->transport->cookie($request, null));
        }

        return $response;
    }

    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(TokenStorageInterface $tokenStorage): JsonResponse
    {
        $user = $tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return new JsonResponse(['user' => $this->userData($user)], headers: ['Cache-Control' => 'no-store']);
    }

    private function tokenResponse(Request $request, AuthResult $result, AuthClientType $clientType): JsonResponse
    {
        $data = [
            'access_token' => $this->jwt->issue((string) $result->user->getId(), $result->sessionId),
            'token_type' => 'Bearer',
            'expires_in' => JwtService::ACCESS_TTL,
            'user' => $this->userData($result->user),
        ];
        if ($clientType === AuthClientType::MOBILE) {
            $data['refresh_token'] = $result->refreshToken;
        }
        $response = new JsonResponse($data, headers: ['Cache-Control' => 'no-store']);
        if ($clientType === AuthClientType::WEB) {
            $response->headers->setCookie($this->transport->cookie($request, $result->refreshToken, $result->expiresAt));
        }

        return $response;
    }

    private function clientType(Request $request): AuthClientType
    {
        return AuthClientType::from($request->attributes->get('auth_client'));
    }

    /** @return array{id: ?int, name: string, email: string, role: string, status: string} */
    private function userData(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->value,
            'status' => $user->getStatus()->value,
        ];
    }
}
