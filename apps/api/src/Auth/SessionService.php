<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\User;
use App\Enum\AuthClientType;
use App\Enum\UserStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * All credential-producing operations own their transaction and commit before returning.
 * Mutations lock the user first, then the session, then its token. User updates use
 * the same first lock, including the database trigger that revokes sessions.
 */
final class SessionService
{
    public const SESSION_TTL = 30 * 24 * 60 * 60;

    private readonly Connection $connection;
    private ?string $dummyHash = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    public function login(string $email, #[SensitiveParameter] string $password, AuthClientType $client): AuthResult
    {
        $this->requireOwnTransaction();
        $email = strtolower(trim($email));
        $hasher = $this->hashers->getPasswordHasher(User::class);
        // Do this before looking up the account, using the configured hasher/cost
        // for both known and missing users. This hash is never persisted.
        $this->dummyHash ??= $hasher->hash(bin2hex(random_bytes(32)));

        return $this->connection->transactional(function () use ($email, $password, $client, $hasher): AuthResult {
            $row = $this->connection->fetchAssociative(
                'SELECT id, password_hash, status FROM users WHERE email_normalized = ? FOR UPDATE',
                [$email],
            );
            $verified = $hasher->verify($row === false ? $this->dummyHash : $row['password_hash'], $password);
            if ($row === false || !$verified || $row['status'] !== UserStatus::ACTIVE->value) {
                throw $this->unauthorized();
            }

            $userId = (int) $row['id'];
            $now = $this->now();
            if ($hasher->needsRehash($row['password_hash'])) {
                // The trigger also revokes older sessions on a hash upgrade. The
                // newly authenticated session is created after this update.
                $this->connection->executeStatement(
                    'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
                    [$hasher->hash($password), $this->date($now), $userId],
                );
            }

            $sessionId = bin2hex(random_bytes(16));
            $expiresAt = $now->modify('+'.self::SESSION_TTL.' seconds');
            $this->connection->insert('auth_sessions', [
                'id' => $sessionId,
                'user_id' => $userId,
                'client_type' => $client->value,
                'created_at' => $this->date($now),
                'last_used_at' => $this->date($now),
                'expires_at' => $this->date($expiresAt),
                'revoked_at' => null,
            ]);
            [, $rawToken] = $this->createRefreshToken($sessionId, $now, $expiresAt);

            return new AuthResult($this->currentUser($userId), $sessionId, $rawToken, $expiresAt);
        });
    }

    public function refresh(#[SensitiveParameter] string $refreshToken, AuthClientType $client): AuthResult
    {
        $this->requireOwnTransaction();
        if (!$this->isToken($refreshToken)) {
            throw $this->unauthorized();
        }

        $hash = hash('sha256', $refreshToken);
        $result = $this->connection->transactional(function () use ($hash, $client): ?AuthResult {
            $locked = $this->lockTokenFamily($hash);
            if ($locked === null) {
                return null;
            }
            [$user, $session, $token] = $locked;
            $now = $this->now();
            if ($user['status'] !== UserStatus::ACTIVE->value
                || $session['client_type'] !== $client->value
                || $session['revoked_at'] !== null
                || new DateTimeImmutable($session['expires_at']) <= $now
                || new DateTimeImmutable($token['expires_at']) <= $now) {
                return null;
            }

            if ($token['consumed_at'] !== null) {
                $this->revokeFamily($session['id'], $now);
                // Do not throw inside the transaction: replay revocation must
                // COMMIT before the caller receives the generic 401.
                return null;
            }
            if ($token['revoked_at'] !== null) {
                return null;
            }

            $expiresAt = new DateTimeImmutable($session['expires_at']);
            $this->connection->executeStatement(
                'UPDATE refresh_tokens SET consumed_at = ? WHERE id = ?',
                [$this->date($now), $token['id']],
            );
            [$nextId, $rawToken] = $this->createRefreshToken($session['id'], $now, $expiresAt);
            $this->connection->executeStatement(
                'UPDATE refresh_tokens SET replaced_by_id = ? WHERE id = ?',
                [$nextId, $token['id']],
            );
            $this->connection->executeStatement(
                'UPDATE auth_sessions SET last_used_at = ? WHERE id = ?',
                [$this->date($now), $session['id']],
            );

            return new AuthResult($this->currentUser((int) $user['id']), $session['id'], $rawToken, $expiresAt);
        });

        return $result ?? throw $this->unauthorized();
    }

    public function logout(#[SensitiveParameter] string $refreshToken, AuthClientType $client): void
    {
        $this->requireOwnTransaction();
        if (!$this->isToken($refreshToken)) {
            return;
        }
        $this->connection->transactional(function () use ($refreshToken, $client): void {
            $locked = $this->lockTokenFamily(hash('sha256', $refreshToken));
            if ($locked !== null && $locked[1]['client_type'] === $client->value) {
                // A consumed token still identifies its family for idempotent logout.
                $this->revokeFamily($locked[1]['id'], $this->now());
            }
        });
    }

    public function authenticate(string $subject, string $sessionId): User
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $subject) !== 1
            || (int) $subject > 2147483647
            || preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw $this->unauthorized();
        }

        $userId = $this->connection->fetchOne(<<<'SQL'
            SELECT u.id
            FROM users u
            INNER JOIN auth_sessions s ON s.user_id = u.id
            WHERE u.id = ? AND u.status = 'ACTIVE'
              AND s.id = ? AND s.revoked_at IS NULL
              AND s.expires_at > clock_timestamp()
            SQL, [(int) $subject, $sessionId]);
        if ($userId === false) {
            throw $this->unauthorized();
        }

        // Never authorize using a previously managed User with an old role/status.
        $user = $this->currentUser((int) $userId);
        if ($user->getStatus() !== UserStatus::ACTIVE) {
            throw $this->unauthorized();
        }

        return $user;
    }

    public function revokeUserSessions(int $userId): void
    {
        $this->connection->transactional(function () use ($userId): void {
            if ($this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === false) {
                return;
            }
            $now = $this->date($this->now());
            $this->connection->executeStatement(
                'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?) WHERE user_id = ?',
                [$now, $userId],
            );
            $this->connection->executeStatement(<<<'SQL'
                UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, ?)
                WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)
                SQL, [$now, $userId]);
        });
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}|null */
    private function lockTokenFamily(string $hash): ?array
    {
        // This first lookup acquires no row locks; every fact is checked again
        // after locks are obtained in the shared user -> session -> token order.
        $lookup = $this->connection->fetchAssociative(<<<'SQL'
            SELECT t.id, t.session_id, s.user_id
            FROM refresh_tokens t INNER JOIN auth_sessions s ON s.id = t.session_id
            WHERE t.token_hash = ?
            SQL, [$hash]);
        if ($lookup === false) {
            return null;
        }
        $user = $this->connection->fetchAssociative('SELECT id, status FROM users WHERE id = ? FOR UPDATE', [$lookup['user_id']]);
        $session = $this->connection->fetchAssociative(
            'SELECT * FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE',
            [$lookup['session_id'], $lookup['user_id']],
        );
        $token = $this->connection->fetchAssociative(
            'SELECT * FROM refresh_tokens WHERE id = ? AND session_id = ? AND token_hash = ? FOR UPDATE',
            [$lookup['id'], $lookup['session_id'], $hash],
        );

        return $user === false || $session === false || $token === false ? null : [$user, $session, $token];
    }

    /** @return array{string, string} */
    private function createRefreshToken(string $sessionId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): array
    {
        $id = bin2hex(random_bytes(16));
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->insert('refresh_tokens', [
            'id' => $id,
            'session_id' => $sessionId,
            'token_hash' => hash('sha256', $rawToken),
            'created_at' => $this->date($now),
            'expires_at' => $this->date($expiresAt),
            'consumed_at' => null,
            'revoked_at' => null,
            'replaced_by_id' => null,
        ]);

        return [$id, $rawToken];
    }

    private function revokeFamily(string $sessionId, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?',
            [$this->date($now), $sessionId],
        );
        $this->connection->executeStatement(
            'UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE session_id = ?',
            [$this->date($now), $sessionId],
        );
    }

    private function currentUser(int $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw $this->unauthorized();
        }
        $this->entityManager->refresh($user);

        return $user;
    }

    private function requireOwnTransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('Session credential operations must own the outermost transaction.');
        }
    }

    private function isToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(gmdate('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:sP');
    }

    private function unauthorized(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException('Bearer');
    }
}
