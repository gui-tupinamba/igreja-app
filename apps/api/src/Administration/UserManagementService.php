<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Http\UserInput;
use App\Security\AuthenticatedActor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Profile, credential and audit changes share one authorized transaction. */
final readonly class UserManagementService
{
    private Connection $connection;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PasswordHasherFactoryInterface $hashers,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    public function create(AuthenticatedActor $actor, #[SensitiveParameter] array $data): User
    {
        $data = UserInput::create($data);
        $this->requireOwnTransaction();

        try {
            return $this->connection->transactional(function () use ($actor, $data): User {
                [, $now] = $this->lockAndAuthorize($actor, null, false, $data['role']);
                $passwordHash = $this->hashers->getPasswordHasher(User::class)->hash($data['password']);
                $id = (int) $this->connection->fetchOne(<<<'SQL'
                    INSERT INTO users (name, email, email_normalized, password_hash, phone,
                        birth_date, role, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id
                    SQL, [
                        $data['name'], $data['email'], strtolower($data['email']), $passwordHash,
                        $data['phone'] ?? null, ($data['birth_date'] ?? null)?->format('Y-m-d'),
                        $data['role']->value, $data['status']->value, $now, $now,
                    ]);
                $this->audit($actor->userId, $id, 'user.created', [
                    'role' => $data['role']->value,
                    'status' => $data['status']->value,
                ], $now);

                return $this->currentUser($id);
            });
        } catch (UniqueConstraintViolationException) {
            // No email value, SQL or parameters are exposed in a collision response.
            throw new ConflictHttpException();
        }
    }

    public function updateProfile(AuthenticatedActor $actor, int $targetId, array $data, bool $self = false): User
    {
        $data = UserInput::profile($data, $self);
        $this->requireOwnTransaction();

        try {
            return $this->connection->transactional(function () use ($actor, $targetId, $data, $self): User {
                [$target, $now] = $this->lockAndAuthorize($actor, $targetId, $self);
                $changes = [];
                foreach (['name', 'email', 'phone', 'birth_date'] as $field) {
                    if (!array_key_exists($field, $data)) {
                        continue;
                    }
                    $value = $field === 'birth_date' ? $data[$field]?->format('Y-m-d') : $data[$field];
                    if ($target[$field] !== $value) {
                        $changes[$field] = $value;
                    }
                }
                if ($changes !== []) {
                    // Audit field names only: never copy names, contacts or dates of birth.
                    $changedFields = array_keys($changes);
                    $emailChanged = array_key_exists('email', $changes)
                        && strtolower($changes['email']) !== $target['email_normalized'];
                    if (array_key_exists('email', $changes)) {
                        $changes['email_normalized'] = strtolower($changes['email']);
                    }
                    $changes['updated_at'] = $now;
                    $this->connection->update('users', $changes, ['id' => $targetId]);
                    if ($emailChanged) {
                        $this->revokeSessions($targetId, $now);
                    }
                    $this->audit($actor->userId, $targetId, 'user.profile_changed', [
                        'changed_fields' => $changedFields,
                    ], $now);
                }

                return $this->currentUser($targetId);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException();
        }
    }

    public function changePassword(
        AuthenticatedActor $actor,
        int $targetId,
        #[SensitiveParameter] string $newPassword,
        #[SensitiveParameter] ?string $currentPassword = null,
        bool $self = false,
    ): void {
        $newPassword = UserInput::password($newPassword);
        $this->requireOwnTransaction();

        $this->connection->transactional(function () use ($actor, $targetId, $newPassword, $currentPassword, $self): void {
            [$target, $now] = $this->lockAndAuthorize($actor, $targetId, $self);
            $hasher = $this->hashers->getPasswordHasher(User::class);
            if ($self && ($currentPassword === null || $currentPassword === ''
                || strlen($currentPassword) > 72 || str_contains($currentPassword, "\0")
                || !$hasher->verify($target['password_hash'], $currentPassword))) {
                throw new AccessDeniedHttpException();
            }
            if ($hasher->verify($target['password_hash'], $newPassword)) {
                throw new UnprocessableEntityHttpException();
            }
            // The existing PostgreSQL trigger revokes every target session and
            // refresh token, including this session for a self password change.
            $this->connection->update('users', [
                'password_hash' => $hasher->hash($newPassword),
                'updated_at' => $now,
            ], ['id' => $targetId]);
            $this->audit($actor->userId, $targetId, 'user.password_changed', [
                'changed_fields' => ['password'],
            ], $now);
            // Keep an already managed User consistent without exposing its hash.
            $this->currentUser($targetId);
        });
    }

    /** @return array{0: array<string, mixed>|null, 1: string} */
    private function lockAndAuthorize(
        AuthenticatedActor $actor,
        ?int $targetId,
        bool $self,
        ?UserRole $createdRole = null,
    ): array {
        // Shared with bootstrap and access changes, including concurrent creation
        // and demotion. Users precede sessions in every credential mutation path.
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, email, email_normalized, password_hash, phone, birth_date, role, status
             FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
            [$actor->userId, $targetId ?? $actor->userId],
        );
        $users = [];
        foreach ($rows as $row) {
            $users[(int) $row['id']] = $row;
        }
        $actingUser = $users[$actor->userId] ?? null;
        if ($actingUser === null || $actingUser['status'] !== UserStatus::ACTIVE->value) {
            throw new UnauthorizedHttpException('Bearer');
        }
        $session = $this->connection->fetchAssociative(
            'SELECT revoked_at, expires_at FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE',
            [$actor->sessionId, $actor->userId],
        );
        // Transaction start time cannot prove a session remained valid while
        // this request waited for another administrator to release the lock.
        $now = (string) $this->connection->fetchOne("SELECT date_trunc('second', clock_timestamp())");
        if ($session === false || $session['revoked_at'] !== null
            || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) {
            throw new UnauthorizedHttpException('Bearer');
        }
        if ($self) {
            if ($targetId !== $actor->userId) {
                throw new AccessDeniedHttpException();
            }
        } elseif (!in_array($actingUser['role'], [UserRole::ADMIN->value, UserRole::PASTOR->value], true)) {
            throw new AccessDeniedHttpException();
        }
        $target = $targetId === null ? null : ($users[$targetId] ?? throw new NotFoundHttpException());
        if (!$self && $actingUser['role'] === UserRole::PASTOR->value
            && (($target['role'] ?? null) === UserRole::ADMIN->value || $createdRole === UserRole::ADMIN)) {
            throw new AccessDeniedHttpException();
        }

        return [$target, $now];
    }

    private function revokeSessions(int $targetId, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?) WHERE user_id = ?',
            [$now, $targetId],
        );
        $this->connection->executeStatement(<<<'SQL'
            UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, ?)
            WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)
            SQL, [$now, $targetId]);
    }

    private function audit(int $actorId, int $targetId, string $action, array $metadata, string $now): void
    {
        $this->connection->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'users',
            'entity_id' => $targetId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'request_id' => bin2hex(random_bytes(16)),
            'created_at' => $now,
        ]);
    }

    private function currentUser(int $id): User
    {
        $user = $this->entityManager->find(User::class, $id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException();
        }
        $this->entityManager->refresh($user);

        return $user;
    }

    private function requireOwnTransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('User management changes must own the outermost transaction.');
        }
    }
}
