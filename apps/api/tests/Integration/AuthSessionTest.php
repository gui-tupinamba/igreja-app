<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\LoginThrottle;
use App\Auth\SessionService;
use App\Entity\AuthLoginLimit;
use App\Entity\AuthSession;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Enum\AuthClientType;
use App\Enum\UserRole;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class AuthSessionTest extends KernelTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private ?Connection $connection = null;
    private SessionService $sessions;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $limitIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires an isolated migrated PostgreSQL database.');
        }
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        self::assertStringEndsWith('_test', (string) $this->connection->fetchOne('SELECT current_database()'));
        $this->sessions = self::getContainer()->get(SessionService::class);
        // Session operations intentionally own and commit their transactions;
        // fixtures are cleaned by ID, never by truncate or a surrounding rollback.
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection?->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach ($this->userIds as $userId) {
                $this->connection->executeStatement(<<<'SQL'
                    UPDATE refresh_tokens SET replaced_by_id = NULL
                    WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)
                    SQL, [$userId]);
                $this->connection->executeStatement(<<<'SQL'
                    DELETE FROM refresh_tokens
                    WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)
                    SQL, [$userId]);
                $this->connection->delete('auth_sessions', ['user_id' => $userId]);
                $this->connection->delete('users', ['id' => $userId]);
            }
            foreach ($this->limitIds as $id) {
                $this->connection->delete('auth_login_limits', ['id' => $id]);
            }
        } finally {
            $this->entityManager?->clear();
            $this->entityManager = null;
            $this->connection = null;
            $this->userIds = $this->limitIds = [];
            parent::tearDown();
        }
    }

    public function testRotationStoresOnlyHashesPreservesAbsoluteExpiryAndReplaysCommitRevocation(): void
    {
        [$user, $password] = $this->createUser();
        $first = $this->sessions->login(strtoupper($user->getEmail()), $password, AuthClientType::MOBILE);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $first->sessionId);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $first->refreshToken);
        $next = $this->sessions->refresh($first->refreshToken, AuthClientType::MOBILE);
        self::assertSame($first->sessionId, $next->sessionId);
        self::assertEquals($first->expiresAt, $next->expiresAt);
        self::assertNotSame($first->refreshToken, $next->refreshToken);

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM refresh_tokens WHERE session_id = ?', [$first->sessionId]);
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing([hash('sha256', $first->refreshToken), hash('sha256', $next->refreshToken)], array_column($rows, 'token_hash'));
        self::assertStringNotContainsString($first->refreshToken, json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($next->refreshToken, json_encode($rows, JSON_THROW_ON_ERROR));

        $session = $this->entityManager->find(AuthSession::class, $first->sessionId);
        self::assertInstanceOf(AuthSession::class, $session);
        self::assertSame(AuthClientType::MOBILE, $session->getClientType());
        self::assertEquals($first->expiresAt, $session->getExpiresAt());
        self::assertSame(SessionService::SESSION_TTL, $session->getExpiresAt()->getTimestamp() - $session->getCreatedAt()->getTimestamp());
        $token = $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => hash('sha256', $first->refreshToken)]);
        self::assertInstanceOf(RefreshToken::class, $token);
        self::assertNotNull($token->getConsumedAt());
        self::assertSame(hash('sha256', $next->refreshToken), $token->getReplacedBy()?->getTokenHash());

        $this->assertUnauthorized(fn () => $this->sessions->refresh($first->refreshToken, AuthClientType::MOBILE));
        self::assertFalse($this->connection->isTransactionActive());
        $independent = DriverManager::getConnection($this->connection->getParams());
        try {
            self::assertNotNull($independent->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$first->sessionId]));
            self::assertSame(2, (int) $independent->fetchOne('SELECT count(*) FROM refresh_tokens WHERE session_id = ? AND revoked_at IS NOT NULL', [$first->sessionId]));
        } finally {
            $independent->close();
        }
        $this->assertUnauthorized(fn () => $this->sessions->refresh($next->refreshToken, AuthClientType::MOBILE));
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $user->getId(), $first->sessionId));
    }

    public function testTransportMismatchDoesNotConsumeOrRevokeAndLogoutWithConsumedTokenRevokesOnlyItsFamily(): void
    {
        [$user, $password] = $this->createUser();
        $web = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        $mobile = $this->sessions->login($user->getEmail(), $password, AuthClientType::MOBILE);
        $this->assertUnauthorized(fn () => $this->sessions->refresh($web->refreshToken, AuthClientType::MOBILE));
        $this->sessions->logout($web->refreshToken, AuthClientType::MOBILE);
        self::assertNull($this->connection->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$web->sessionId]));
        $rotated = $this->sessions->refresh($web->refreshToken, AuthClientType::WEB);
        $this->sessions->logout($web->refreshToken, AuthClientType::WEB);
        $this->sessions->logout($web->refreshToken, AuthClientType::WEB);
        $this->sessions->logout('malformed', AuthClientType::WEB);
        $this->assertUnauthorized(fn () => $this->sessions->refresh($rotated->refreshToken, AuthClientType::WEB));
        self::assertSame($user->getId(), $this->sessions->authenticate((string) $user->getId(), $mobile->sessionId)->getId());
    }

    public function testAuthenticateRefreshesManagedRolesAndRejectsAnotherUserAndExpiredSession(): void
    {
        [$user, $password] = $this->createUser();
        [$other] = $this->createUser();
        $session = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $other->getId(), $session->sessionId));
        $this->connection->update('users', ['role' => 'PASTOR'], ['id' => $user->getId()]);
        self::assertSame(UserRole::MEMBER, $user->getRole());
        self::assertSame(UserRole::PASTOR, $this->sessions->authenticate((string) $user->getId(), $session->sessionId)->getRole());

        $this->connection->executeStatement(<<<'SQL'
            UPDATE auth_sessions SET created_at = clock_timestamp() - interval '31 days',
                expires_at = clock_timestamp() - interval '1 hour' WHERE id = ?
            SQL, [$session->sessionId]);
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $user->getId(), $session->sessionId));
        $this->assertUnauthorized(fn () => $this->sessions->refresh($session->refreshToken, AuthClientType::WEB));
    }

    #[DataProvider('revokingUserChanges')]
    public function testDatabaseTriggerRevokesAllSessionsAndReactivationCannotResurrectThem(string $change): void
    {
        [$user, $password] = $this->createUser();
        $first = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        $second = $this->sessions->login($user->getEmail(), $password, AuthClientType::MOBILE);
        if ($change === 'password_hash') {
            $this->connection->update('users', ['password_hash' => password_hash('new-test-credential', PASSWORD_BCRYPT, ['cost' => 4])], ['id' => $user->getId()]);
        } else {
            $this->connection->update('users', ['status' => $change], ['id' => $user->getId()]);
            $this->connection->update('users', ['status' => 'ACTIVE'], ['id' => $user->getId()]);
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM auth_sessions WHERE user_id = ? AND revoked_at IS NOT NULL', [$user->getId()]));
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $user->getId(), $first->sessionId));
        $this->assertUnauthorized(fn () => $this->sessions->refresh($second->refreshToken, AuthClientType::MOBILE));
    }

    public static function revokingUserChanges(): iterable
    {
        yield ['password_hash'];
        yield ['INACTIVE'];
        yield ['BLOCKED'];
    }

    public function testPasswordRehashRevokesOldSessionsAndKeepsNewLoginUsable(): void
    {
        [$user, $password] = $this->createUser();
        $old = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        $factory = new PasswordHasherFactory([User::class => ['algorithm' => 'bcrypt', 'cost' => 5]]);
        $upgradingSessions = new SessionService($this->entityManager, $factory);
        $new = $upgradingSessions->login($user->getEmail(), $password, AuthClientType::WEB);
        self::assertFalse($factory->getPasswordHasher(User::class)->needsRehash($new->user->getPasswordHash()));
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $user->getId(), $old->sessionId));
        self::assertSame($user->getId(), $upgradingSessions->authenticate((string) $user->getId(), $new->sessionId)->getId());
    }

    public function testExplicitRevocationCoversAllDevices(): void
    {
        [$user, $password] = $this->createUser();
        $first = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        $second = $this->sessions->login($user->getEmail(), $password, AuthClientType::MOBILE);
        $this->sessions->revokeUserSessions((int) $user->getId());
        $this->assertUnauthorized(fn () => $this->sessions->refresh($first->refreshToken, AuthClientType::WEB));
        $this->assertUnauthorized(fn () => $this->sessions->refresh($second->refreshToken, AuthClientType::MOBILE));
    }

    public function testDurableThrottleCountsNormalizedAccountsAndResetsAtNextFixedWindow(): void
    {
        $secret = 'test-only-throttle-key-'.bin2hex(random_bytes(8));
        $throttle = new LoginThrottle($this->connection, $secret);
        $email = 'limit-'.bin2hex(random_bytes(8)).'@example.test';
        $ip = '198.51.100.27';
        $accountId = hash_hmac('sha256', 'account:'.$email, $secret);
        $ipId = hash_hmac('sha256', 'ip:'.$ip, $secret);
        $this->limitIds = [$accountId, $ipId];
        for ($i = 0; $i < 10; ++$i) {
            $throttle->consume(strtoupper($email), $ip);
        }
        try {
            $throttle->consume(' '.$email.' ', $ip);
            self::fail('The eleventh normalized-account attempt must be throttled.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertGreaterThanOrEqual(1, (int) $exception->getHeaders()['Retry-After']);
            self::assertLessThanOrEqual(900, (int) $exception->getHeaders()['Retry-After']);
        }
        self::assertFalse($this->connection->isTransactionActive());
        $independent = DriverManager::getConnection($this->connection->getParams());
        try {
            self::assertSame(11, (int) $independent->fetchOne('SELECT attempts FROM auth_login_limits WHERE id = ?', [$accountId]));
        } finally {
            $independent->close();
        }
        $entity = $this->entityManager->find(AuthLoginLimit::class, $accountId);
        self::assertInstanceOf(AuthLoginLimit::class, $entity);
        self::assertSame(11, $entity->getAttempts());
        self::assertSame(0, $entity->getWindowStartedAt()->getTimestamp() % 900);
        $this->connection->executeStatement("UPDATE auth_login_limits SET window_started_at = window_started_at - interval '15 minutes' WHERE id IN (?, ?)", $this->limitIds);
        $throttle->consume($email, $ip);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT attempts FROM auth_login_limits WHERE id = ?', [$accountId]));
        $this->connection->update('auth_login_limits', ['attempts' => 100], ['id' => $ipId]);
        $this->expectException(TooManyRequestsHttpException::class);
        $throttle->consume($email, $ip);
    }

    #[DataProvider('schemaViolations')]
    public function testAuthenticationConstraintsRejectDirectInvalidWrites(string $sql, string $sqlState): void
    {
        [$user, $password] = $this->createUser();
        $session = $this->sessions->login($user->getEmail(), $password, AuthClientType::WEB);
        try {
            $this->connection->executeStatement($sql, [$session->sessionId]);
            self::fail('Authentication constraint violation was accepted.');
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState());
        }
    }

    public static function schemaViolations(): iterable
    {
        yield ['UPDATE auth_sessions SET client_type = \'OTHER\' WHERE id = ?', '23514'];
        yield ['UPDATE auth_sessions SET expires_at = created_at WHERE id = ?', '23514'];
        yield ['UPDATE refresh_tokens SET token_hash = \'plaintext-token\' WHERE session_id = ?', '23514'];
        yield ['UPDATE refresh_tokens SET consumed_at = created_at - interval \'1 second\' WHERE session_id = ?', '23514'];
        yield ['UPDATE refresh_tokens SET replaced_by_id = id, consumed_at = created_at WHERE session_id = ?', '23514'];
        yield ['UPDATE refresh_tokens SET session_id = repeat(\'f\', 32) WHERE session_id = ?', '23503'];
        yield [<<<'SQL'
            INSERT INTO refresh_tokens (id, session_id, token_hash, created_at, expires_at)
            SELECT repeat('e', 32), session_id, repeat('e', 64), created_at, expires_at
            FROM refresh_tokens WHERE session_id = ?
            SQL, '23505'];
    }

    public function testTwoConcurrentRefreshesCreateOneSuccessorThenRevokeTheFamily(): void
    {
        [$user, $password] = $this->createUser();
        $session = $this->sessions->login($user->getEmail(), $password, AuthClientType::MOBILE);
        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->getId()]);
        $results = $this->runConcurrent([
            ['action' => 'refresh', 'token' => $session->refreshToken, 'client' => 'MOBILE'],
            ['action' => 'refresh', 'token' => $session->refreshToken, 'client' => 'MOBILE'],
        ]);
        self::assertEqualsCanonicalizing(['ok', 'unauthorized'], $results);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM refresh_tokens WHERE session_id = ?', [$session->sessionId]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM refresh_tokens WHERE session_id = ? AND consumed_at IS NOT NULL', [$session->sessionId]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM refresh_tokens WHERE session_id = ? AND revoked_at IS NOT NULL', [$session->sessionId]));
        $this->assertUnauthorized(fn () => $this->sessions->authenticate((string) $user->getId(), $session->sessionId));
    }

    public function testConcurrentBootstrapCreatesExactlyOneAdministrator(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE role = 'ADMIN'"));
        $suffix = bin2hex(random_bytes(8));
        $emails = ['concurrent-admin-a-'.$suffix.'@example.test', 'concurrent-admin-b-'.$suffix.'@example.test'];
        $password = 'test-only-'.bin2hex(random_bytes(12));
        $this->connection->beginTransaction();
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
        try {
            $results = $this->runConcurrent([
                ['action' => 'admin', 'email' => $emails[0], 'password' => $password],
                ['action' => 'admin', 'email' => $emails[1], 'password' => $password],
            ]);
            self::assertEqualsCanonicalizing(['ok', 'refused'], $results);
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE role = 'ADMIN'"));
        } finally {
            foreach ($emails as $email) {
                $id = $this->connection->fetchOne('SELECT id FROM users WHERE email_normalized = ?', [$email]);
                if ($id !== false) {
                    $this->userIds[] = (int) $id;
                }
            }
        }
    }

    /** @return array{User, string} */
    private function createUser(): array
    {
        $password = 'test-only-'.bin2hex(random_bytes(10));
        $hash = self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class)->hash($password);
        $user = new User('Session test user', 'session-'.bin2hex(random_bytes(8)).'@example.test', $hash);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        self::assertNotNull($user->getId());
        $this->userIds[] = $user->getId();

        return [$user, $password];
    }

    private function assertUnauthorized(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a generic authentication rejection.');
        } catch (UnauthorizedHttpException $exception) {
            self::assertSame(401, $exception->getStatusCode());
            self::assertSame(['WWW-Authenticate' => 'Bearer'], $exception->getHeaders());
            self::assertSame('', $exception->getMessage());
        }
    }

    /** @param list<array<string, string>> $inputs @return list<string> */
    private function runConcurrent(array $inputs): array
    {
        if (!function_exists('proc_open')) {
            self::fail('Concurrency acceptance requires proc_open in the test runtime.');
        }
        $workers = [];
        try {
            foreach ($inputs as $input) {
                $input['label'] = 'auth-concurrency-'.bin2hex(random_bytes(8));
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__).'/Support/auth-concurrency-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 2),
                );
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes, $input['label']];
            }
            foreach ($workers as [, $pipes]) {
                self::assertSame("READY\n", fgets($pipes[1]), 'A worker did not reach the guarded test connection.');
            }

            // Both independent PostgreSQL connections must actually be waiting
            // on the parent lock before it is released. This proves overlap,
            // rather than relying on two fast sequential PHP calls.
            $deadline = microtime(true) + 10;
            do {
                $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $this->connection->fetchOne(<<<'SQL'
                    SELECT count(*) FROM pg_stat_activity
                    WHERE application_name IN (?, ?) AND wait_event_type = 'Lock'
                    SQL, [$workers[0][2], $workers[1][2]]);
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both workers must overlap at the database lock.');
            $this->connection->commit();

            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), 'Concurrency worker failed: '.$output);
                self::assertSame('', $errors, 'Concurrency worker unexpectedly wrote to stderr.');
                $result = json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR);
                $results[] = $result['status'];
            }
            $workers = [];

            return $results;
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }
}
