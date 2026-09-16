<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\JwtService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class JwtServiceTest extends TestCase
{
    private function service(): JwtService
    {
        return new JwtService($_ENV['JWT_PRIVATE_KEY_PATH'], $_ENV['JWT_PUBLIC_KEY_PATH'], 'test-issuer', 'test-audience');
    }

    public function testIssuedTokenContainsOnlyMinimalClaimsAndExpiresInTenMinutes(): void
    {
        $jwt = $this->service();
        $token = $jwt->issue('123', str_repeat('a', 32));
        self::assertSame(['sub' => '123', 'sid' => str_repeat('a', 32)], $jwt->verify($token));
        $claims = (array) JWT::decode($token, new \Firebase\JWT\Key(file_get_contents($_ENV['JWT_PUBLIC_KEY_PATH']), 'RS256'));
        self::assertSame(['sub', 'sid', 'iat', 'exp', 'iss', 'aud'], array_keys($claims));
        self::assertSame(600, $claims['exp'] - $claims['iat']);
    }

    #[DataProvider('invalidClaims')]
    public function testCryptographicallyValidTokensStillRequireStrictClaims(array $overrides, ?string $remove = null): void
    {
        $claims = array_replace(['sub' => '123', 'sid' => str_repeat('a', 32), 'iat' => time(), 'exp' => time() + 600, 'iss' => 'test-issuer', 'aud' => 'test-audience'], $overrides);
        if ($remove !== null) {
            unset($claims[$remove]);
        }
        $token = JWT::encode($claims, file_get_contents($_ENV['JWT_PRIVATE_KEY_PATH']), 'RS256');
        $this->expectException(UnauthorizedHttpException::class);
        $this->service()->verify($token);
    }

    public static function invalidClaims(): iterable
    {
        yield 'wrong issuer' => [['iss' => 'wrong']];
        yield 'wrong audience' => [['aud' => 'wrong']];
        yield 'audience array' => [['aud' => ['test-audience']]];
        yield 'missing exp' => [[], 'exp'];
        yield 'missing sid' => [[], 'sid'];
        yield 'numeric sub' => [['sub' => 123]];
        yield 'zero sub' => [['sub' => '0']];
        yield 'overflow sub' => [['sub' => '2147483648']];
        yield 'bad session' => [['sid' => '../session']];
        yield 'future issue' => [['iat' => time() + 60, 'exp' => time() + 600]];
        yield 'expired' => [['iat' => time() - 601, 'exp' => time() - 1]];
        yield 'excess lifetime' => [['exp' => time() + 3600]];
        yield 'string expiration' => [['exp' => (string) (time() + 600)]];
    }

    #[DataProvider('malformedTokens')]
    public function testUntrustedTokensAreRejected(string $token): void
    {
        $this->expectException(UnauthorizedHttpException::class);
        $this->service()->verify($token);
    }

    public static function malformedTokens(): iterable
    {
        yield [''];
        yield ['not-a-token'];
        yield ['invalid.json.signature'];
        yield [str_repeat('a', 4097).'.x.y'];
        yield ['eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.'];
        foreach ([
            'algorithm array' => ['alg' => ['RS256']],
            'algorithm object' => ['alg' => (object) ['value' => 'RS256']],
            'key identifier array' => ['alg' => 'RS256', 'kid' => ['unexpected']],
        ] as $name => $header) {
            yield $name => [JWT::urlsafeB64Encode(json_encode($header, JSON_THROW_ON_ERROR)).'.eyJzdWIiOiIxIn0.eA'];
        }
    }
}
