<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\AuthenticatedActor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Critical access changes always commit together with their audit record. */
final readonly class UserAccessService
{
    private Connection $connection;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
    }

    public function changeAccess(AuthenticatedActor $actor, int $targetId, ?UserRole $role, ?UserStatus $status): User
    {
        if ($role === null && $status === null) {
            throw new UnprocessableEntityHttpException();
        }
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('User access changes must own the outermost transaction.');
        }

        return $this->connection->transactional(function () use ($actor, $targetId, $role, $status): User {
            // Shared with the initial ADMIN command, including the empty-table case.
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
            // All user locks precede all session locks, matching SessionService and
            // the revocation trigger. Ordering IDs also prevents reciprocal edits deadlocking.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, role, status FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                [$actor->userId, $targetId],
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
            // Read the actual database clock after waiting for locks. Transaction
            // start time could otherwise accept a session that expired while waiting.
            $now = (string) $this->connection->fetchOne("SELECT date_trunc('second', clock_timestamp())");
            if ($session === false || $session['revoked_at'] !== null
                || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) {
                throw new UnauthorizedHttpException('Bearer');
            }
            if (!in_array($actingUser['role'], [UserRole::ADMIN->value, UserRole::PASTOR->value], true)) {
                throw new AccessDeniedHttpException();
            }
            $target = $users[$targetId] ?? throw new NotFoundHttpException();
            $nextRole = $role?->value ?? $target['role'];
            $nextStatus = $status?->value ?? $target['status'];
            if ($actingUser['role'] === UserRole::PASTOR->value
                && ($target['role'] === UserRole::ADMIN->value || $nextRole === UserRole::ADMIN->value)) {
                throw new AccessDeniedHttpException();
            }
            $changed = $target['role'] !== $nextRole || $target['status'] !== $nextStatus;
            if ($changed) {
                if ($target['role'] === UserRole::ADMIN->value && $target['status'] === UserStatus::ACTIVE->value
                    && ($nextRole !== UserRole::ADMIN->value || $nextStatus !== UserStatus::ACTIVE->value)
                    && (int) $this->connection->fetchOne("SELECT count(*) FROM users WHERE role = 'ADMIN' AND status = 'ACTIVE'") <= 1) {
                    throw new ConflictHttpException();
                }
                $this->connection->update('users', [
                    'role' => $nextRole,
                    'status' => $nextStatus,
                    'updated_at' => $now,
                ], ['id' => $targetId]);
                $removed = 0;
                if ($nextRole === UserRole::MEMBER->value || $nextStatus !== UserStatus::ACTIVE->value) {
                    $removed = $this->connection->executeStatement(
                        'UPDATE user_ministries SET is_leader = FALSE, updated_at = ? WHERE user_id = ? AND is_leader = TRUE',
                        [$now, $targetId],
                    );
                }
                // Deliberately avoid serializing an entity, credentials or profile data.
                $this->connection->insert('audit_logs', [
                    'actor_id' => $actor->userId,
                    'action' => 'user.access_changed',
                    'entity_type' => 'users',
                    'entity_id' => $targetId,
                    'metadata' => json_encode([
                        'before' => ['role' => $target['role'], 'status' => $target['status']],
                        'after' => ['role' => $nextRole, 'status' => $nextStatus],
                        'leadership_removed_count' => $removed,
                    ], JSON_THROW_ON_ERROR),
                    'request_id' => bin2hex(random_bytes(16)),
                    'created_at' => $now,
                ]);
            }
            $user = $this->entityManager->find(User::class, $targetId);
            if (!$user instanceof User) {
                throw new NotFoundHttpException();
            }
            $this->entityManager->refresh($user);

            return $user;
        });
    }
}
