<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use UnexpectedValueException;

final readonly class JwtService
{
    public const ACCESS_TTL = 600;

    public function __construct(
        #[Autowire('%env(JWT_PRIVATE_KEY_PATH)%')] private string $privateKeyPath,
        #[Autowire('%env(JWT_PUBLIC_KEY_PATH)%')] private string $publicKeyPath,
        #[Autowire('%env(JWT_ISSUER)%')] private string $issuer,
        #[Autowire('%env(JWT_AUDIENCE)%')] private string $audience,
    ) {
        if ($issuer === '' || $audience === '') {
            throw new RuntimeException('JWT issuer and audience must be configured.');
        }
    }

    public function issue(string $subject, string $sessionId): string
    {
        if (!$this->validIdentity($subject, $sessionId)) {
            throw new RuntimeException('Invalid token identity.');
        }

        $now = time();

        return JWT::encode([
            'sub' => $subject,
            'sid' => $sessionId,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
            'iss' => $this->issuer,
            'aud' => $this->audience,
        ], $this->readKey($this->privateKeyPath), 'RS256');
    }

    /** @return array{sub: string, sid: string} */
    public function verify(#[\SensitiveParameter] string $token): array
    {
        // Key configuration failures are server errors, not invalid credentials.
        $key = new Key($this->readKey($this->publicKeyPath), 'RS256');
        if (strlen($token) > 4096 || substr_count($token, '.') !== 2) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $claims = (array) JWT::decode($token, $key);
            if (!isset($claims['sub'], $claims['sid'], $claims['iat'], $claims['exp'], $claims['iss'], $claims['aud'])
                || !is_string($claims['sub']) || !is_string($claims['sid'])
                || !$this->validIdentity($claims['sub'], $claims['sid'])
                || $claims['iss'] !== $this->issuer || $claims['aud'] !== $this->audience
                || !is_int($claims['iat']) || !is_int($claims['exp'])
                || $claims['iat'] > time() || $claims['exp'] <= time()
                || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > self::ACCESS_TTL
            ) {
                throw new UnexpectedValueException('Invalid claims.');
            }
        } catch (UnexpectedValueException | \DomainException | \TypeError | \InvalidArgumentException $exception) {
            // The previous exception may contain token data; do not attach it.
            throw new UnauthorizedHttpException('Bearer');
        }

        return ['sub' => $claims['sub'], 'sid' => $claims['sid']];
    }

    private function validIdentity(string $subject, string $sessionId): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/D', $subject) === 1
            && (int) $subject <= 2147483647
            && preg_match('/^[a-f0-9]{32}$/D', $sessionId) === 1;
    }

    private function readKey(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('JWT key is unavailable.');
        }
        $key = file_get_contents($path);
        if ($key === false || $key === '') {
            throw new RuntimeException('JWT key is unavailable.');
        }

        return $key;
    }
}
