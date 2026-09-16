<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

/** Real HTTP-kernel and PostgreSQL coverage, with committed fixtures for session transactions. */
final class AuthHttpTest extends WebTestCase
{
    private const PASSWORD = 'HTTP-fixture-password-2026!';
    private const WEB_ORIGIN = 'http://localhost:5173';
    private const REFRESH_COOKIE = '__Host-refresh';

    private ?Connection $connection = null;
    private KernelBrowser $client;
    /** @var list<int> */
    private array $userIds = [];
    /** @var array<string, true> */
    private array $limitIds = [];
    private string $clientIp;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires the isolated migrated PostgreSQL test database.');
        }

        $this->client = self::createClient();
        $managedConnection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // This connection survives BrowserKit kernel reboots and does not hide commits in a test transaction.
        $this->connection = DriverManager::getConnection($managedConnection->getParams());
        self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        if (!str_ends_with((string) $this->connection->fetchOne('SELECT current_database()'), '_test')) {
            self::fail('Refusing to write fixtures outside an actual PostgreSQL database ending in _test.');
        }
        $this->clientIp = '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null) {
                foreach ($this->userIds as $id) {
                    $this->connection->executeStatement(
                        'UPDATE refresh_tokens SET replaced_by_id = NULL WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)',
                        [$id],
                    );
                    $this->connection->executeStatement(
                        'DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)',
                        [$id],
                    );
                    $this->connection->executeStatement('DELETE FROM auth_sessions WHERE user_id = ?', [$id]);
                    $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$id]);
                }
                foreach (array_keys($this->limitIds) as $id) {
                    $this->connection->executeStatement('DELETE FROM auth_login_limits WHERE id = ?', [$id]);
                }
                $this->connection->close();
            }
        } finally {
            $this->connection = null;
            parent::tearDown();
        }
    }

    public function testWebLoginKeepsRefreshOnlyInHostCookieAndMeNeedsBearer(): void
    {
        $user = $this->fixture();
        $this->request('/api/auth/login', ['email' => strtoupper($user['email']), 'password' => self::PASSWORD], 'web');
        self::assertResponseStatusCodeSame(200);
        $login = $this->json();
        $this->assertAuthenticationResponse($login, $user, false);
        $cookie = $this->refreshCookie();
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertNull($cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertGreaterThan(time() + 29 * 86400, $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 30 * 86400 + 5, $cookie->getExpiresTime());
        self::assertStringNotContainsString((string) $cookie->getValue(), (string) $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::WEB_ORIGIN);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');

        $this->request('/api/auth/me', null, cookies: [self::REFRESH_COOKIE => (string) $cookie->getValue()], method: 'GET');
        $this->assertGenericUnauthorized();
        $this->me($login['access_token']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['user' => $login['user']], $this->json());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testMobileLoginAndRefreshNeverSetCookiesAndStoreOnlyRefreshHash(): void
    {
        $user = $this->fixture();
        $login = $this->login($user);
        $this->assertAuthenticationResponse($login, $user, true);
        self::assertCount(0, $this->client->getResponse()->headers->getCookies());
        $stored = $this->connection->fetchOne('SELECT token_hash FROM refresh_tokens WHERE session_id = ?', [$this->claims($login['access_token'])['sid']]);
        self::assertSame(hash('sha256', $login['refresh_token']), $stored);
        self::assertNotSame($login['refresh_token'], $stored);

        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $login['refresh_token']]);
        self::assertResponseStatusCodeSame(200);
        $rotated = $this->json();
        $this->assertAuthenticationResponse($rotated, $user, true);
        self::assertNotSame($login['refresh_token'], $rotated['refresh_token']);
        self::assertCount(0, $this->client->getResponse()->headers->getCookies());
        self::assertSame($this->claims($login['access_token'])['sid'], $this->claims($rotated['access_token'])['sid']);
        self::assertSame('2', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE session_id = ?', [$this->claims($login['access_token'])['sid']]));
        $this->me($rotated['access_token']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testWebRefreshRotatesCookieAndLogoutRevokesServerSession(): void
    {
        $user = $this->fixture();
        $login = $this->login($user, 'web');
        $firstRefresh = (string) $this->refreshCookie()->getValue();
        $this->request('/api/auth/refresh', [], 'web', [self::REFRESH_COOKIE => $firstRefresh]);
        self::assertResponseStatusCodeSame(200);
        $rotated = $this->json();
        $this->assertAuthenticationResponse($rotated, $user, false);
        $secondRefresh = (string) $this->refreshCookie()->getValue();
        self::assertNotSame($firstRefresh, $secondRefresh);

        $this->request('/api/auth/logout', [], 'web', [self::REFRESH_COOKIE => $secondRefresh]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertLessThan(time(), $this->refreshCookie()->getExpiresTime());
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
        $this->me($rotated['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/refresh', [], 'web', [self::REFRESH_COOKIE => $secondRefresh]);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/logout', [], 'web');
        self::assertResponseStatusCodeSame(204);
    }

    public function testRefreshReplayRevokesBothAccessAndSuccessorWithoutAffectingOtherSessions(): void
    {
        $user = $this->fixture();
        $first = $this->login($user);
        $independent = $this->login($user);
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertResponseStatusCodeSame(200);
        $successor = $this->json();

        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $first['refresh_token']]);
        $this->assertGenericUnauthorized();
        $this->me($first['access_token']);
        $this->assertGenericUnauthorized();
        $this->me($successor['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $successor['refresh_token']]);
        $this->assertGenericUnauthorized();
        $this->me($independent['access_token']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testMobileLogoutIsIdempotentAndRevokesAccessImmediately(): void
    {
        $login = $this->login($this->fixture());
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->request('/api/auth/mobile/logout', ['refresh_token' => $login['refresh_token']]);
            self::assertResponseStatusCodeSame(204);
            self::assertCount(0, $this->client->getResponse()->headers->getCookies());
        }
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertGenericUnauthorized();
    }

    #[DataProvider('deniedCredentials')]
    public function testBadAndDisabledCredentialsHaveTheSameGenericResponse(string $status, bool $wrongPassword, bool $unknownUser): void
    {
        $user = $this->fixture($status);
        $this->request('/api/auth/mobile/login', [
            'email' => $unknownUser ? 'missing-'.$user['email'] : $user['email'],
            'password' => $wrongPassword ? 'incorrect-fixture-password' : self::PASSWORD,
        ]);
        $this->assertGenericUnauthorized();
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM auth_sessions WHERE user_id = ?', [$user['id']]));
    }

    public static function deniedCredentials(): iterable
    {
        yield 'wrong password' => ['ACTIVE', true, false];
        yield 'unknown account' => ['ACTIVE', false, true];
        yield 'inactive account' => ['INACTIVE', false, false];
        yield 'blocked account' => ['BLOCKED', false, false];
    }

    public function testMeReadsCurrentRoleAndBlockingRevokesExistingCredentials(): void
    {
        $user = $this->fixture();
        $login = $this->login($user);
        $this->connection->executeStatement("UPDATE users SET role = 'LEADER' WHERE id = ?", [$user['id']]);
        $this->me($login['access_token']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('LEADER', $this->json()['user']['role']);

        $this->connection->executeStatement("UPDATE users SET status = 'BLOCKED' WHERE id = ?", [$user['id']]);
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertGenericUnauthorized();
        $this->connection->executeStatement("UPDATE users SET status = 'ACTIVE' WHERE id = ?", [$user['id']]);
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
    }

    public function testPasswordChangeInvalidatesAnExistingSession(): void
    {
        $user = $this->fixture();
        $login = $this->login($user);
        $this->connection->executeStatement('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash('changed-fixture-password', PASSWORD_BCRYPT, ['cost' => 4]), $user['id'],
        ]);
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertGenericUnauthorized();
    }

    public function testExpiredAbsoluteSessionCannotAuthenticateOrRotate(): void
    {
        $login = $this->login($this->fixture());
        $sessionId = $this->claims($login['access_token'])['sid'];
        $this->connection->executeStatement(<<<'SQL'
            UPDATE auth_sessions SET created_at = CURRENT_TIMESTAMP - INTERVAL '31 days',
                last_used_at = CURRENT_TIMESTAMP - INTERVAL '1 day', expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'
            WHERE id = ?
            SQL, [$sessionId]);
        $this->me($login['access_token']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertGenericUnauthorized();
    }

    public function testTransportCannotExchangeOrLogoutAnotherPlatformFamily(): void
    {
        $user = $this->fixture();
        $mobile = $this->login($user);
        $web = $this->login($user, 'web');
        $webRefresh = (string) $this->refreshCookie()->getValue();
        $this->request('/api/auth/mobile/refresh', ['refresh_token' => $webRefresh]);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/refresh', [], 'web', [self::REFRESH_COOKIE => $mobile['refresh_token']]);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/logout', ['refresh_token' => $webRefresh]);
        self::assertResponseStatusCodeSame(204);
        $this->me($web['access_token']);
        self::assertResponseStatusCodeSame(200);
        $this->me($mobile['access_token']);
        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('invalidJwtKinds')]
    public function testBearerRejectsInvalidSignatureClaimsAndSessionBinding(string $kind): void
    {
        $login = $this->login($this->fixture());
        $claims = $this->claims($login['access_token']);
        $keyPath = $_ENV['JWT_PRIVATE_KEY_PATH'] ?? getenv('JWT_PRIVATE_KEY_PATH');
        self::assertIsString($keyPath);
        $privateKey = file_get_contents($keyPath);
        self::assertIsString($privateKey);
        $algorithm = 'RS256';
        switch ($kind) {
            case 'expired':
                $claims['iat'] = time() - 1200;
                $claims['exp'] = time() - 600;
                break;
            case 'wrong issuer': $claims['iss'] = 'https://untrusted.example.test'; break;
            case 'wrong audience': $claims['aud'] = 'different-application'; break;
            case 'future issued at': $claims['iat'] = time() + 3600; $claims['exp'] = time() + 4200; break;
            case 'missing expiry': unset($claims['exp']); break;
            case 'different user': $claims['sub'] = (string) $this->fixture()['id']; break;
            case 'nonexistent session': $claims['sid'] = bin2hex(random_bytes(16)); break;
            case 'wrong algorithm': $algorithm = 'HS256'; $privateKey = str_repeat('fixture-only-key-', 4); break;
        }
        $token = JWT::encode($claims, $privateKey, $algorithm);
        if ($kind === 'tampered signature') {
            $segments = explode('.', $token);
            $segments[2][0] = $segments[2][0] === 'a' ? 'b' : 'a';
            $token = implode('.', $segments);
        }
        $this->me($token);
        $this->assertGenericUnauthorized();
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
    }

    public static function invalidJwtKinds(): iterable
    {
        foreach (['expired', 'wrong issuer', 'wrong audience', 'future issued at', 'missing expiry', 'different user', 'nonexistent session', 'wrong algorithm', 'tampered signature'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testAccessCannotComeFromQueryAndAnonymousMeIsUnauthorized(): void
    {
        $login = $this->login($this->fixture());
        $this->request('/api/auth/me?access_token='.rawurlencode($login['access_token']), null, method: 'GET');
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/me', null, headers: ['HTTP_AUTHORIZATION' => 'Basic invalid'], method: 'GET');
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/me', null, method: 'GET');
        $this->assertGenericUnauthorized();
    }

    #[DataProvider('forbiddenTransports')]
    public function testOriginsAndPlatformHeadersPreventCookieCsrf(string $path, string $transport, array $headers, array $cookies): void
    {
        $this->request($path, [], $transport, $cookies, $headers);
        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('error', $this->json());
    }

    public static function forbiddenTransports(): iterable
    {
        foreach (['login', 'refresh', 'logout'] as $action) {
            yield 'web '.$action.' missing origin' => ['/api/auth/'.$action, 'web', ['HTTP_ORIGIN' => null], []];
            yield 'web '.$action.' foreign origin' => ['/api/auth/'.$action, 'web', ['HTTP_ORIGIN' => 'https://attacker.example.test'], []];
            yield 'web '.$action.' null origin' => ['/api/auth/'.$action, 'web', ['HTTP_ORIGIN' => 'null'], []];
            yield 'web '.$action.' missing custom header' => ['/api/auth/'.$action, 'web', ['HTTP_X_AUTH_CLIENT' => null], []];
            yield 'mobile '.$action.' browser origin' => ['/api/auth/mobile/'.$action, 'mobile', ['HTTP_ORIGIN' => self::WEB_ORIGIN], []];
            yield 'mobile '.$action.' browser fetch metadata' => ['/api/auth/mobile/'.$action, 'mobile', ['HTTP_SEC_FETCH_SITE' => 'same-site'], []];
            yield 'mobile '.$action.' cookie present' => ['/api/auth/mobile/'.$action, 'mobile', [], [self::REFRESH_COOKIE => 'fixture-cookie']];
            yield 'mobile '.$action.' wrong custom header' => ['/api/auth/mobile/'.$action, 'mobile', ['HTTP_X_AUTH_CLIENT' => 'web'], []];
        }
    }

    #[DataProvider('badInputs')]
    public function testAuthInputRejectsMalformedTypesUnexpectedFieldsAndMixedRefresh(string $path, string $transport, string $body, array $headers, int $expectedStatus): void
    {
        $this->request($path, $body, $transport, headers: $headers);
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertArrayHasKey('error', $this->json());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public static function badInputs(): iterable
    {
        yield 'malformed json' => ['/api/auth/mobile/login', 'mobile', '{', [], 400];
        yield 'form body' => ['/api/auth/mobile/login', 'mobile', 'email=test&password=test', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 400];
        yield 'json list' => ['/api/auth/mobile/login', 'mobile', '[]', [], 400];
        yield 'missing credentials' => ['/api/auth/mobile/login', 'mobile', '{}', [], 422];
        yield 'email array' => ['/api/auth/mobile/login', 'mobile', '{"email":[],"password":"fixture"}', [], 422];
        yield 'password integer' => ['/api/auth/mobile/login', 'mobile', '{"email":"fixture@example.test","password":123}', [], 422];
        yield 'invalid email' => ['/api/auth/mobile/login', 'mobile', '{"email":"invalid","password":"fixture"}', [], 422];
        yield 'extra role' => ['/api/auth/mobile/login', 'mobile', '{"email":"fixture@example.test","password":"fixture","role":"ADMIN"}', [], 422];
        yield 'oversized password' => ['/api/auth/mobile/login', 'mobile', json_encode(['email' => 'fixture@example.test', 'password' => str_repeat('x', 5000)], JSON_THROW_ON_ERROR), [], 422];
        yield 'mobile missing refresh' => ['/api/auth/mobile/refresh', 'mobile', '{}', [], 422];
        yield 'mobile boolean refresh' => ['/api/auth/mobile/refresh', 'mobile', '{"refresh_token":true}', [], 422];
        yield 'mobile extra refresh field' => ['/api/auth/mobile/refresh', 'mobile', '{"refresh_token":"fixture","user_id":1}', [], 422];
        yield 'mobile missing logout token' => ['/api/auth/mobile/logout', 'mobile', '{}', [], 422];
        yield 'web refresh cannot accept body token' => ['/api/auth/refresh', 'web', '{"refresh_token":"fixture"}', [], 422];
        yield 'web logout cannot accept body token' => ['/api/auth/logout', 'web', '{"refresh_token":"fixture"}', [], 422];
    }

    public function testCorsPreflightAllowsOnlyExactWebOriginAndExplicitHeaders(): void
    {
        $this->request('/api/auth/login', null, 'web', headers: [
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-auth-client',
        ], method: 'OPTIONS');
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::WEB_ORIGIN);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
        self::assertStringContainsString('Origin', (string) $this->client->getResponse()->headers->get('Vary'));

        $this->request('/api/auth/login', null, 'web', headers: [
            'HTTP_ORIGIN' => self::WEB_ORIGIN.'.attacker.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-auth-client',
        ], method: 'OPTIONS');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function testLoginAccountBudgetIsSharedAcrossIpsAndEmailCase(): void
    {
        $user = $this->fixture();
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->request('/api/auth/mobile/login', [
                'email' => $attempt % 2 === 0 ? $user['email'] : strtoupper($user['email']),
                'password' => 'invalid-fixture-password',
            ], headers: ['REMOTE_ADDR' => $this->nextClientIp()]);
            $this->assertGenericUnauthorized();
        }
        $this->request('/api/auth/mobile/login', ['email' => $user['email'], 'password' => self::PASSWORD], headers: ['REMOTE_ADDR' => $this->nextClientIp()]);
        $this->assertThrottled();
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM auth_sessions WHERE user_id = ?', [$user['id']]));
    }

    public function testIpBudgetAppliesAcrossAccountsAndCannotBeBypassedByForwardedFor(): void
    {
        // Prepare only this test's isolated client counter near its limit; HTTP exercises both remaining attempts.
        $limitId = $this->limitId('ip:'.$this->clientIp);
        $this->limitIds[$limitId] = true;
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO auth_login_limits (id, attempts, window_started_at)
            VALUES (?, 99, to_timestamp(floor(extract(epoch FROM clock_timestamp()) / 900) * 900))
            SQL, [$limitId]);
        $first = $this->fixture();
        $second = $this->fixture();
        $this->request('/api/auth/mobile/login', ['email' => $first['email'], 'password' => 'wrong-fixture-password']);
        $this->assertGenericUnauthorized();
        $this->request('/api/auth/mobile/login', ['email' => $second['email'], 'password' => self::PASSWORD], headers: [
            'HTTP_X_FORWARDED_FOR' => $this->nextClientIp(),
        ]);
        $this->assertThrottled();
        self::assertSame('0', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM auth_sessions WHERE user_id = ?', [$second['id']]));
    }

    public function testExpiredThrottleWindowAllowsTheNextLogin(): void
    {
        $user = $this->fixture();
        $limitId = $this->limitId('account:'.$user['email']);
        $this->limitIds[$limitId] = true;
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO auth_login_limits (id, attempts, window_started_at)
            VALUES (?, 100, to_timestamp(floor(extract(epoch FROM clock_timestamp()) / 900) * 900) - INTERVAL '1 hour')
            SQL, [$limitId]);
        $this->login($user);
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT attempts FROM auth_login_limits WHERE id = ?', [$limitId]));
    }

    /** @return array{id: int, email: string, name: string} */
    private function fixture(string $status = 'ACTIVE'): array
    {
        static $passwordHash = null;
        $passwordHash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $email = 'http-'.bin2hex(random_bytes(10)).'@example.test';
        $id = (int) $this->connection->fetchOne(<<<'SQL'
            INSERT INTO users (name, email, email_normalized, password_hash, role, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'MEMBER', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) RETURNING id
            SQL, ['HTTP fixture', $email, $email, $passwordHash, $status]);
        $this->userIds[] = $id;

        return ['id' => $id, 'email' => $email, 'name' => 'HTTP fixture'];
    }

    /** @param array{id: int, email: string, name: string} $user */
    private function login(array $user, string $transport = 'mobile'): array
    {
        $this->request($transport === 'web' ? '/api/auth/login' : '/api/auth/mobile/login', [
            'email' => $user['email'], 'password' => self::PASSWORD,
        ], $transport);
        self::assertResponseStatusCodeSame(200);

        return $this->json();
    }

    /** @param array<string, mixed>|string|null $body @param array<string, string> $cookies @param array<string, string|null> $headers */
    private function request(string $path, array|string|null $body, string $transport = 'mobile', array $cookies = [], array $headers = [], string $method = 'POST'): void
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $name => $value) {
            $this->client->getCookieJar()->set(new BrowserCookie($name, $value, null, '/', 'localhost', true));
        }
        $server = [
            'HTTP_HOST' => 'localhost', 'HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp,
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTH_CLIENT' => $transport,
        ];
        if ($transport === 'web') {
            $server['HTTP_ORIGIN'] = self::WEB_ORIGIN;
        }
        foreach ($headers as $name => $value) {
            if ($value === null) {
                unset($server[$name]);
            } else {
                $server[$name] = $value;
            }
        }
        $content = is_array($body) ? json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR) : $body;
        if (str_ends_with($path, '/login') && $method === 'POST') {
            $this->limitIds[$this->limitId('ip:'.$server['REMOTE_ADDR'])] = true;
            $data = is_array($body) ? $body : json_decode($body ?? '', true);
            if (is_array($data) && isset($data['email']) && is_string($data['email'])) {
                $this->limitIds[$this->limitId('account:'.strtolower(trim($data['email'])))] = true;
            }
        }
        $this->client->request($method, 'https://localhost'.$path, server: $server, content: $content);
    }

    private function me(string $accessToken): void
    {
        $this->request('/api/auth/me', null, headers: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken], method: 'GET');
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function refreshCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::REFRESH_COOKIE) {
                return $cookie;
            }
        }
        self::fail('The response did not set the secure host-only refresh cookie.');
    }

    private function assertGenericUnauthorized(): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Autenticação necessária.']], $this->json());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertStringNotContainsString(self::PASSWORD, (string) $this->client->getResponse()->getContent());
    }

    private function assertThrottled(): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertSame('TOO_MANY_REQUESTS', $this->json()['error']['code']);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertLessThanOrEqual(900, (int) $retryAfter);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    private function limitId(string $key): string
    {
        return hash_hmac('sha256', $key, $_ENV['APP_SECRET'] ?? (string) getenv('APP_SECRET'));
    }

    private function nextClientIp(): string
    {
        return '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
    }

    private function assertAuthenticationResponse(array $response, array $user, bool $mobile): void
    {
        $expectedKeys = ['access_token', 'token_type', 'expires_in', 'user'];
        if ($mobile) {
            $expectedKeys[] = 'refresh_token';
            self::assertIsString($response['refresh_token']);
            self::assertGreaterThanOrEqual(43, strlen($response['refresh_token']));
        }
        self::assertEqualsCanonicalizing($expectedKeys, array_keys($response));
        self::assertSame('Bearer', $response['token_type']);
        self::assertSame(600, $response['expires_in']);
        self::assertSame([
            'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => 'MEMBER', 'status' => 'ACTIVE',
        ], $response['user']);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertStringNotContainsString(self::PASSWORD, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('password_hash', (string) $this->client->getResponse()->getContent());
        $claims = $this->claims($response['access_token']);
        self::assertSame((string) $user['id'], $claims['sub']);
        self::assertSame(600, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('email', $claims);
        self::assertArrayNotHasKey('role', $claims);
        self::assertArrayNotHasKey('password_hash', $claims);
    }

    private function claims(string $token): array
    {
        $segments = explode('.', $token);
        self::assertCount(3, $segments);

        return json_decode(base64_decode(strtr($segments[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    }
}
