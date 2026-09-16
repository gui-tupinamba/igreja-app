<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enum\AuthClientType;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** The browser cookie and native body transports are deliberately disjoint. */
final readonly class AuthTransport
{
    public function __construct(private array $allowedOrigins, private bool $allowInsecureLocal)
    {
        foreach ($allowedOrigins as $origin) {
            $parts = is_string($origin) ? parse_url($origin) : false;
            if ($parts === false || !isset($parts['scheme'], $parts['host'])
                || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
                || !in_array($parts['scheme'], ['https', 'http'], true)
                || str_contains($origin, '*')
                || ($parts['scheme'] === 'http' && !$this->isLoopback($parts['host']))
            ) {
                throw new InvalidArgumentException('Configure exact HTTPS origins or loopback development origins.');
            }
        }
    }

    public function isAllowedOrigin(?string $origin): bool
    {
        return $origin !== null && in_array($origin, $this->allowedOrigins, true);
    }

    public function assertSecureRequest(Request $request): void
    {
        if (!$request->isSecure() && !$this->isLocalHttp($request)) {
            throw new AccessDeniedHttpException();
        }
    }

    /** @return array<string, mixed> */
    public function input(Request $request, AuthClientType $clientType): array
    {
        $this->assertSecureRequest($request);

        if ($clientType === AuthClientType::WEB) {
            if ($request->headers->get('X-Auth-Client') !== 'web'
                || !$this->isAllowedOrigin($request->headers->get('Origin'))
            ) {
                throw new AccessDeniedHttpException();
            }
        } else {
            if ($request->headers->get('X-Auth-Client') !== 'mobile'
                || $request->headers->has('Origin')
                || $request->headers->has('Cookie')
                || $request->cookies->count() !== 0
            ) {
                throw new AccessDeniedHttpException();
            }
            foreach ($request->headers->keys() as $header) {
                if (str_starts_with(strtolower($header), 'sec-fetch-')) {
                    throw new AccessDeniedHttpException();
                }
            }
        }

        $contentType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
        if ($contentType !== 'application/json') {
            throw new BadRequestHttpException();
        }
        $content = $request->getContent();
        if (strlen($content) > 8192) {
            throw new BadRequestHttpException();
        }
        try {
            $data = json_decode($content, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException();
        }
        if (!$data instanceof stdClass) {
            throw new BadRequestHttpException();
        }

        return (array) $data;
    }

    /** @param array<string, mixed> $data @return array{string, string} */
    public function credentials(array $data): array
    {
        $this->onlyFields($data, ['email', 'password']);
        if (!isset($data['email'], $data['password']) || !is_string($data['email']) || !is_string($data['password'])) {
            throw new UnprocessableEntityHttpException();
        }
        $email = strtolower(trim($data['email']));
        if ($email === '' || strlen($email) > 180 || preg_match('/[^\x00-\x7f]/', $email) === 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $data['password'] === '' || strlen($data['password']) > 4096 || str_contains($data['password'], "\0")
        ) {
            throw new UnprocessableEntityHttpException();
        }

        return [$email, $data['password']];
    }

    /** @param array<string, mixed> $data */
    public function refreshToken(Request $request, AuthClientType $clientType, array $data, bool $allowMissing = false): ?string
    {
        $this->onlyFields($data, $clientType === AuthClientType::WEB ? [] : ['refresh_token']);
        $token = $clientType === AuthClientType::WEB
            ? $request->cookies->get($this->cookieName($request))
            : ($data['refresh_token'] ?? null);

        if ($token === null && $allowMissing && $clientType === AuthClientType::WEB) {
            return null;
        }
        if (!is_string($token) || $token === '' || strlen($token) > 256) {
            // A missing browser credential is an authentication failure, not a malformed body.
            if ($clientType === AuthClientType::WEB) {
                throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer');
            }
            throw new UnprocessableEntityHttpException();
        }

        return $token;
    }

    public function cookie(Request $request, ?string $token, ?DateTimeImmutable $expiresAt = null): Cookie
    {
        return Cookie::create($this->cookieName($request))
            ->withValue($token)
            ->withExpires($token === null ? 1 : $expiresAt)
            ->withPath('/')
            ->withSecure(!$this->isLocalHttp($request))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public function cookieName(Request $request): string
    {
        return $this->isLocalHttp($request) ? 'igreja_refresh_dev' : '__Host-refresh';
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function onlyFields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new UnprocessableEntityHttpException();
        }
    }

    private function isLocalHttp(Request $request): bool
    {
        return $this->allowInsecureLocal && !$request->isSecure() && $this->isLoopback($request->getHost());
    }

    private function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }
}
