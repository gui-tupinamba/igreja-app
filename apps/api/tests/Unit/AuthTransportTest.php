<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\AuthTransport;
use App\Enum\AuthClientType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class AuthTransportTest extends TestCase
{
    private const ORIGIN = 'https://app.church.example';

    #[DataProvider('browserOriginAttacks')]
    public function testBrowserAuthenticationRequiresAnExactAllowedOrigin(?string $origin): void
    {
        $request = $this->request();
        $origin === null ? $request->headers->remove('Origin') : $request->headers->set('Origin', $origin);
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport()->input($request, AuthClientType::WEB);
    }

    public static function browserOriginAttacks(): iterable
    {
        yield 'absent' => [null];
        yield 'opaque origin' => ['null'];
        yield 'untrusted site' => ['https://evil.example'];
        yield 'lookalike suffix' => [self::ORIGIN . '.evil.example'];
        yield 'trailing slash' => [self::ORIGIN . '/'];
        yield 'different scheme' => ['http://app.church.example'];
        yield 'multiple origins' => [self::ORIGIN . ', https://evil.example'];
    }

    #[DataProvider('browserMetadata')]
    public function testNativeEndpointCannotBypassBrowserCookieProtection(string $header, string $value): void
    {
        $request = $this->request(mobile: true);
        $request->headers->set($header, $value);
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport()->input($request, AuthClientType::MOBILE);
    }

    public static function browserMetadata(): iterable
    {
        yield 'origin' => ['Origin', self::ORIGIN];
        yield 'cookie' => ['Cookie', '__Host-refresh=secret'];
        yield 'empty cookie header' => ['Cookie', ''];
        yield 'fetch mode' => ['Sec-Fetch-Mode', 'cors'];
        yield 'fetch site' => ['Sec-Fetch-Site', 'same-site'];
        yield 'fetch destination' => ['Sec-Fetch-Dest', 'empty'];
        yield 'fetch user' => ['Sec-Fetch-User', '?1'];
        yield 'wrong client marker' => ['X-Auth-Client', 'web'];
    }

    public function testNativeRequestRejectsParsedCookiesEvenWithoutRawHeader(): void
    {
        $request = $this->request(mobile: true);
        $request->cookies->set('__Host-refresh', 'secret');
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport()->input($request, AuthClientType::MOBILE);
    }

    public function testBrowserRequiresCustomHeaderEvenWhenOriginIsAllowed(): void
    {
        $request = $this->request();
        $request->headers->remove('X-Auth-Client');
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport()->input($request, AuthClientType::WEB);
    }

    #[DataProvider('malformedBodies')]
    public function testOnlyBoundedJsonObjectsAreAccepted(string $content): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->transport()->input($this->request($content), AuthClientType::WEB);
    }

    public static function malformedBodies(): iterable
    {
        yield 'array' => ['[]'];
        yield 'null' => ['null'];
        yield 'empty' => [''];
        yield 'broken' => ['{"email":'];
        yield 'oversized' => ['{"value":"' . str_repeat('x', 8192) . '"}'];
        yield 'deep nesting' => [str_repeat('{"value":', 17) . 'null' . str_repeat('}', 17)];
    }

    public function testFormEncodedContentCannotBecomeAJsonLogin(): void
    {
        $request = $this->request();
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
        $this->expectException(BadRequestHttpException::class);
        $this->transport()->input($request, AuthClientType::WEB);
    }

    #[DataProvider('invalidCredentials')]
    public function testCredentialValidationRejectsMassAssignmentAndUnexpectedTypes(array $data): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->transport()->credentials($data);
    }

    public static function invalidCredentials(): iterable
    {
        yield 'role injection' => [['email' => 'member@example.test', 'password' => 'valid', 'role' => 'ADMIN']];
        yield 'missing password' => [['email' => 'member@example.test']];
        yield 'email array' => [['email' => ['member@example.test'], 'password' => 'valid']];
        yield 'password array' => [['email' => 'member@example.test', 'password' => ['valid']]];
        yield 'non ASCII email' => [['email' => 'mémbro@example.test', 'password' => 'valid']];
        yield 'invalid email' => [['email' => 'member', 'password' => 'valid']];
        yield 'oversized password' => [['email' => 'member@example.test', 'password' => str_repeat('x', 4097)]];
        yield 'NUL password' => [['email' => 'member@example.test', 'password' => "valid\0suffix"]];
    }

    public function testEmailIsNormalizedWithoutTrimmingThePassword(): void
    {
        self::assertSame(['member@example.test', ' passphrase '], $this->transport()->credentials([
            'email' => '  MEMBER@EXAMPLE.TEST ', 'password' => ' passphrase ',
        ]));
    }

    public function testWebRejectsRefreshTokenInBodyEvenWithValidCookie(): void
    {
        $request = $this->request();
        $request->cookies->set('__Host-refresh', 'cookie-token');
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->transport()->refreshToken($request, AuthClientType::WEB, ['refresh_token' => 'body-token']);
    }

    public function testWebRefreshRequiresItsCookieAndLogoutCanBeIdempotent(): void
    {
        $request = $this->request();
        self::assertNull($this->transport()->refreshToken($request, AuthClientType::WEB, [], allowMissing: true));
        $this->expectException(UnauthorizedHttpException::class);
        $this->transport()->refreshToken($request, AuthClientType::WEB, []);
    }

    public function testProductionCookieHasAllBrowserSecurityProperties(): void
    {
        $expiresAt = new DateTimeImmutable('+30 days');
        $cookie = $this->transport()->cookie($this->request(), 'refresh-token', $expiresAt);
        self::assertSame('__Host-refresh', $cookie->getName());
        self::assertSame('refresh-token', $cookie->getValue());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertNull($cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame($expiresAt->getTimestamp(), $cookie->getExpiresTime());
        self::assertTrue($this->transport()->cookie($this->request(), null)->isCleared());
    }

    public function testInsecureHttpRequiresExplicitOptIn(): void
    {
        $request = $this->request(url: 'http://localhost/api/auth/login');
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport()->input($request, AuthClientType::WEB);
    }

    public function testDevelopmentOptInDoesNotAllowNonLoopbackHttp(): void
    {
        $request = $this->request(url: 'http://api.church.example/api/auth/login');
        $this->expectException(AccessDeniedHttpException::class);
        $this->transport(allowInsecureLocal: true)->input($request, AuthClientType::WEB);
    }

    public function testLocalCookiesUseASeparateNameAndHttpsStillUsesSecureCookies(): void
    {
        $transport = $this->transport(allowInsecureLocal: true);
        $localRequest = $this->request(url: 'http://127.0.0.1/api/auth/login');
        self::assertSame([], $transport->input($localRequest, AuthClientType::WEB));
        $cookie = $transport->cookie($localRequest, 'local-refresh', new DateTimeImmutable('+1 day'));
        self::assertSame('igreja_refresh_dev', $cookie->getName());
        self::assertFalse($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('__Host-refresh', $transport->cookie($this->request(), null)->getName());
        self::assertTrue($transport->cookie($this->request(), null)->isSecure());
    }

    public function testHttpLoopbackFrontendIsCompatibleWithHttpsApiWithoutInsecureCookies(): void
    {
        $transport = new AuthTransport(['http://localhost:5173'], false);
        $request = $this->request();
        $request->headers->set('Origin', 'http://localhost:5173');
        self::assertSame([], $transport->input($request, AuthClientType::WEB));
        self::assertTrue($transport->cookie($request, null)->isSecure());
    }

    #[DataProvider('invalidOriginsConfiguration')]
    public function testAllowlistCannotContainWildcardsOrNonOriginUrls(string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthTransport([$origin], true);
    }

    public static function invalidOriginsConfiguration(): iterable
    {
        yield ['*'];
        yield ['https://*.church.example'];
        yield ['https://app.church.example/path'];
        yield ['https://app.church.example?query=1'];
        yield ['https://user:password@app.church.example'];
        yield ['http://app.church.example'];
    }

    private function transport(bool $allowInsecureLocal = false): AuthTransport
    {
        return new AuthTransport([self::ORIGIN], $allowInsecureLocal);
    }

    private function request(string $content = '{}', bool $mobile = false, string $url = 'https://api.church.example/api/auth/login'): Request
    {
        $request = Request::create($url, 'POST', content: $content);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Auth-Client', $mobile ? 'mobile' : 'web');
        if (!$mobile) {
            $request->headers->set('Origin', self::ORIGIN);
        }

        return $request;
    }
}
