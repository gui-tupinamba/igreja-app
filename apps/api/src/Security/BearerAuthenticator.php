<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\AuthTransport;
use App\Auth\SessionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class BearerAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const PUBLIC_ROUTES = [
        'api_health', 'api_ready',
        'api_auth_login', 'api_auth_refresh', 'api_auth_logout',
        'api_auth_mobile_login', 'api_auth_mobile_refresh', 'api_auth_mobile_logout',
    ];

    public function __construct(
        private readonly JwtService $jwt,
        private readonly SessionService $sessions,
        private readonly AuthTransport $transport,
    ) {
    }

    // public function supports(Request $request): ?bool
    // {
    //     $route = $request->attributes->get('_route');

    //     // Routing errors retain their 404/405; public login/refresh never consume stale access tokens.
    //     return $route !== null && !in_array($route, self::PUBLIC_ROUTES, true);
    // }

//     public function supports(Request $request): ?bool
// {
//     // Não autenticar preflight CORS
//     if ($request->isMethod('OPTIONS')) {
//         return false;
//     }

//     // Rotas públicas de autenticação
//     $publicRoutes = [
//         '/api/auth/login',
//         '/api/auth/refresh',
//     ];

//     if (in_array($request->getPathInfo(), $publicRoutes, true)) {
//         return false;
//     }

//     // Só tenta autenticar quando realmente existe Bearer Token
//     $authorization = $request->headers->get('Authorization');

//     return $authorization !== null
//         && str_starts_with($authorization, 'Bearer ');
// }

        public function supports(Request $request): ?bool
    {
        $route = $request->attributes->get('_route');

        return $route !== null
            && !in_array($route, self::PUBLIC_ROUTES, true);
    }


    public function authenticate(Request $request): Passport
    {
        $this->transport->assertSecureRequest($request);
        $authorization = $request->headers->get('Authorization', '');
        if (strlen($authorization) > 8192 || preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $authorization, $matches) !== 1) {
            throw new CustomUserMessageAuthenticationException('Authentication required.');
        }
        try {
            $claims = $this->jwt->verify($matches[1]);
            $user = $this->sessions->authenticate($claims['sub'], $claims['sid']);
            $request->attributes->set('_auth_session_id', $claims['sid']);
        } catch (UnauthorizedHttpException) {
            // Never attach the original exception or JWT to Symfony security log context.
            throw new CustomUserMessageAuthenticationException('Authentication required.');
        }

        return new SelfValidatingPassport(new UserBadge((string) $user->getId(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Autenticação necessária.'],
        ], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store', 'WWW-Authenticate' => 'Bearer']);
    }
}
