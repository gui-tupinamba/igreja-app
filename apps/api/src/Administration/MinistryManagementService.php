<?php

declare(strict_types=1);

namespace App\Administration;

use App\Http\MinistryInput;
use App\Security\AuthenticatedActor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class MinistryManagementService
{
    public function __construct(private Connection $db)
    {
    }

    public function create(AuthenticatedActor $actor, array $data): array
    {
        $data = MinistryInput::ministry($data, true);
        return $this->transaction($actor, null, function (array $user, ?array $target, string $now) use ($data): array {
            $this->requireGlobal($user);
            $row = $this->db->fetchAssociative(
                'INSERT INTO ministries (name, slug, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) RETURNING *',
                [$data['name'], $data['slug'], $data['description'], $data['status'], $now, $now],
            );
            $this->audit((int) $user['id'], 'ministries', (int) $row['id'], 'ministry.created', ['status' => $data['status']], $now);
            return $row;
        });
    }

    public function update(AuthenticatedActor $actor, int $id, array $data): array
    {
        $data = MinistryInput::ministry($data);
        return $this->transaction($actor, null, function (array $user, ?array $target, string $now) use ($id, $data): array {
            $ministry = $this->lockMinistry($id);
            if (!$this->global($user)) {
                // Leaders can edit descriptive fields only, never slug or status.
                if ($user['role'] !== 'LEADER' || $ministry['status'] !== 'ACTIVE'
                    || array_diff(array_keys($data), ['name', 'description']) !== []
                    || $this->db->fetchOne("SELECT id FROM user_ministries WHERE ministry_id = ? AND user_id = ? AND status = 'ACTIVE' AND is_leader = TRUE FOR UPDATE", [$id, $user['id']]) === false) {
                    throw new AccessDeniedHttpException();
                }
            }
            $changes = array_filter($data, static fn (mixed $value, string $key): bool => $ministry[$key] !== $value, ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $fields = array_keys($changes);
                $this->db->update('ministries', [...$changes, 'updated_at' => $now], ['id' => $id]);
                // Deactivation preserves relationships as history; all permission
                // predicates also require the ministry to be active.
                $metadata = ['changed_fields' => $fields];
                if (isset($changes['status'])) {
                    $metadata['before_status'] = $ministry['status'];
                    $metadata['after_status'] = $changes['status'];
                }
                $this->audit((int) $user['id'], 'ministries', $id, 'ministry.updated', $metadata, $now);
            }
            return $this->db->fetchAssociative('SELECT * FROM ministries WHERE id = ?', [$id]);
        });
    }

    public function addMember(AuthenticatedActor $actor, int $ministryId, int $userId): array
    {
        return $this->membership($actor, $ministryId, $userId, 'join');
    }

    public function removeMember(AuthenticatedActor $actor, int $ministryId, int $userId): array
    {
        return $this->membership($actor, $ministryId, $userId, 'leave');
    }

    public function grantLeadership(AuthenticatedActor $actor, int $ministryId, int $userId, bool $promoteToLeader = false): array
    {
        return $this->membership($actor, $ministryId, $userId, 'lead', $promoteToLeader);
    }

    public function removeLeadership(AuthenticatedActor $actor, int $ministryId, int $userId): array
    {
        return $this->membership($actor, $ministryId, $userId, 'unlead');
    }

    private function membership(AuthenticatedActor $actor, int $ministryId, int $userId, string $action, bool $promote = false): array
    {
        return $this->transaction($actor, $userId, function (array $user, ?array $target, string $now) use ($ministryId, $userId, $action, $promote): array {
            $this->requireGlobal($user);
            if ($target === null) {
                throw new NotFoundHttpException();
            }
            if ($user['role'] === 'PASTOR' && $target['role'] === 'ADMIN') {
                throw new AccessDeniedHttpException();
            }
            $ministry = $this->lockMinistry($ministryId);
            $activating = in_array($action, ['join', 'lead'], true);
            if ($activating && ($target['status'] !== 'ACTIVE' || $ministry['status'] !== 'ACTIVE')) {
                throw new ConflictHttpException();
            }
            if ($action === 'lead' && $target['role'] === 'MEMBER') {
                if (!$promote) {
                    throw new ConflictHttpException();
                }
                $this->db->update('users', ['role' => 'LEADER', 'updated_at' => $now], ['id' => $userId]);
                $this->audit((int) $user['id'], 'users', $userId, 'user.access_changed', [
                    'before' => ['role' => 'MEMBER', 'status' => $target['status']],
                    'after' => ['role' => 'LEADER', 'status' => $target['status']],
                    'leadership_removed_count' => 0,
                ], $now);
            }
            $before = $this->db->fetchAssociative('SELECT * FROM user_ministries WHERE ministry_id = ? AND user_id = ? FOR UPDATE', [$ministryId, $userId]);
            if ($before === false) {
                if (!$activating) {
                    throw new NotFoundHttpException();
                }
                $id = (int) $this->db->fetchOne(
                    "INSERT INTO user_ministries (user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,'ACTIVE',?,?,?) RETURNING id",
                    [$userId, $ministryId, $action === 'lead' ? 'true' : 'false', $now, $now, $now],
                );
                $changed = true;
            } else {
                $id = (int) $before['id'];
                $changes = [];
                if ($activating && $before['status'] !== 'ACTIVE') {
                    $changes = ['status' => 'ACTIVE', 'is_leader' => 'false', 'joined_at' => $now, 'left_at' => null];
                }
                if ($action === 'lead' && (!$before['is_leader'] || $before['status'] !== 'ACTIVE')) {
                    $changes['is_leader'] = 'true';
                }
                if ($action === 'leave' && $before['status'] !== 'INACTIVE') {
                    $changes = ['status' => 'INACTIVE', 'is_leader' => 'false', 'left_at' => $now];
                }
                if ($action === 'unlead' && $before['is_leader']) {
                    $changes['is_leader'] = 'false';
                }
                $changed = $changes !== [];
                if ($changed) {
                    $this->db->update('user_ministries', [...$changes, 'updated_at' => $now], ['id' => $id]);
                }
            }
            $after = $this->db->fetchAssociative('SELECT * FROM user_ministries WHERE id = ?', [$id]);
            if ($changed) {
                $this->audit((int) $user['id'], 'user_ministries', $id, 'membership.'.$action, [
                    'user_id' => $userId, 'ministry_id' => $ministryId,
                    'before' => $before === false ? null : ['status' => $before['status'], 'is_leader' => (bool) $before['is_leader']],
                    'after' => ['status' => $after['status'], 'is_leader' => (bool) $after['is_leader']],
                ], $now);
            }
            return $after;
        });
    }

    private function transaction(AuthenticatedActor $actor, ?int $targetId, callable $operation): array
    {
        if ($this->db->isTransactionActive()) {
            throw new \LogicException('Ministry changes must own the outermost transaction.');
        }
        try {
            return $this->db->transactional(function () use ($actor, $targetId, $operation): array {
                $this->db->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
                $rows = $this->db->fetchAllAssociative('SELECT id, role, status FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE', [$actor->userId, $targetId ?? $actor->userId]);
                $users = [];
                foreach ($rows as $row) { $users[(int) $row['id']] = $row; }
                $user = $users[$actor->userId] ?? null;
                if ($user === null || $user['status'] !== 'ACTIVE') {
                    throw new UnauthorizedHttpException('Bearer');
                }
                $session = $this->db->fetchAssociative('SELECT revoked_at, expires_at FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$actor->sessionId, $actor->userId]);
                $now = (string) $this->db->fetchOne("SELECT date_trunc('second',clock_timestamp())");
                if ($session === false || $session['revoked_at'] !== null || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) {
                    throw new UnauthorizedHttpException('Bearer');
                }
                return $operation($user, $targetId === null ? null : ($users[$targetId] ?? null), $now);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException();
        }
    }

    private function lockMinistry(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ministries WHERE id = ? FOR UPDATE', [$id]);
        return $row === false ? throw new NotFoundHttpException() : $row;
    }

    private function global(array $user): bool
    {
        return in_array($user['role'], ['ADMIN', 'PASTOR'], true);
    }

    private function requireGlobal(array $user): void
    {
        if (!$this->global($user)) { throw new AccessDeniedHttpException(); }
    }

    private function audit(int $actorId, string $entityType, int $entityId, string $action, array $metadata, string $now): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actorId, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'request_id' => bin2hex(random_bytes(16)), 'created_at' => $now]);
    }
}
