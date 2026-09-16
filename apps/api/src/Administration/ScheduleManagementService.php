<?php

declare(strict_types=1);

namespace App\Administration;

use App\Enum\ScheduleStatus;
use App\Http\ScheduleInput;
use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class ScheduleManagementService
{
    public function __construct(private Connection $db, private AccessPolicy $policy)
    {
    }

    public function create(AuthenticatedActor $actor, array $data): array
    {
        $data = ScheduleInput::data($data, true);
        ScheduleInput::audience($data['ministry_id'], $data['visibility']);
        ScheduleInput::interval($data['starts_at'], $data['ends_at']);
        return $this->transaction($actor, function (string $now) use ($actor, $data): array {
            $this->lockMinistries($actor->userId, [$data['ministry_id']]);
            $this->requireDestination($actor->userId, $data['ministry_id'], true);
            $row = $this->db->fetchAssociative("INSERT INTO ministry_schedules
                (created_by,ministry_id,title,description,starts_at,ends_at,visibility,status,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,'DRAFT',?,?) RETURNING *",
                [$actor->userId, $data['ministry_id'], $data['title'], $data['description'], $data['starts_at'], $data['ends_at'], $data['visibility'], $now, $now]);
            $this->audit($actor->userId, (int) $row['id'], 'schedule.created', ['ministry_id' => $data['ministry_id'], 'visibility' => $data['visibility'], 'status' => 'DRAFT'], $now);
            return $row;
        });
    }

    public function update(AuthenticatedActor $actor, int $id, array $data): array
    {
        $data = ScheduleInput::data($data);
        return $this->transaction($actor, function (string $now) use ($actor, $id, $data): array {
            $schedule = $this->lockSchedule($id);
            $origin = $schedule['ministry_id'] === null ? null : (int) $schedule['ministry_id'];
            $destination = array_key_exists('ministry_id', $data) ? $data['ministry_id'] : $origin;
            $this->lockMinistries($actor->userId, [$origin, $destination]);
            $this->requireOrigin($actor->userId, $origin);
            ScheduleInput::audience($destination, $data['visibility'] ?? $schedule['visibility']);
            $this->requireDestination($actor->userId, $destination, $origin !== $destination);
            $merged = [...$schedule, ...$data];
            ScheduleInput::interval($merged['starts_at'], $merged['ends_at']);
            $changes = [];
            foreach ($data as $field => $value) {
                $old = $field === 'ministry_id' ? $origin : $schedule[$field];
                if (in_array($field, ['starts_at','ends_at'], true) && $old !== null) { $old = (new DateTimeImmutable($old))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'); }
                if ($old !== $value) { $changes[$field] = $value; }
            }
            if ($changes !== []) {
                $fields = array_keys($changes);
                $this->db->update('ministry_schedules', [...$changes, 'updated_at' => $now], ['id' => $id]);
                $this->audit($actor->userId, $id, 'schedule.updated', ['changed_fields' => $fields,
                    'before' => ['ministry_id' => $origin, 'visibility' => $schedule['visibility']],
                    'after' => ['ministry_id' => $destination, 'visibility' => $data['visibility'] ?? $schedule['visibility']]], $now);
            }
            return $this->db->fetchAssociative('SELECT * FROM ministry_schedules WHERE id = ?', [$id]);
        });
    }

    public function transition(AuthenticatedActor $actor, int $id, ScheduleStatus $status): array
    {
        return $this->transaction($actor, function (string $now) use ($actor, $id, $status): array {
            $schedule = $this->lockSchedule($id);
            $ministry = $schedule['ministry_id'] === null ? null : (int) $schedule['ministry_id'];
            $this->lockMinistries($actor->userId, [$ministry]);
            $this->requireOrigin($actor->userId, $ministry);
            if ($status === ScheduleStatus::PUBLISHED) { $this->requireDestination($actor->userId, $ministry, true); }
            // Cancelling a draft/archive must never expose unpublished content.
            if ($status === ScheduleStatus::CANCELLED && !in_array($schedule['status'], ['PUBLISHED','CANCELLED'], true)) { throw new ConflictHttpException(); }
            if ($schedule['status'] !== $status->value) {
                $changes = ['status' => $status->value, 'updated_at' => $now];
                $this->db->update('ministry_schedules', $changes, ['id' => $id]);
                $this->audit($actor->userId, $id, 'schedule.status_changed', ['before' => $schedule['status'], 'after' => $status->value], $now);
            }
            return $this->db->fetchAssociative('SELECT * FROM ministry_schedules WHERE id = ?', [$id]);
        });
    }

    private function transaction(AuthenticatedActor $actor, callable $operation): array
    {
        if ($this->db->isTransactionActive()) { throw new \LogicException('Schedule changes must own the outermost transaction.'); }
        return $this->db->transactional(function () use ($actor, $operation): array {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
            $user = $this->db->fetchAssociative('SELECT role,status FROM users WHERE id = ? FOR UPDATE', [$actor->userId]);
            if ($user === false || $user['status'] !== 'ACTIVE') { throw new UnauthorizedHttpException('Bearer'); }
            $session = $this->db->fetchAssociative('SELECT revoked_at,expires_at FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$actor->sessionId, $actor->userId]);
            $now = (string) $this->db->fetchOne("SELECT date_trunc('second',clock_timestamp())");
            if ($session === false || $session['revoked_at'] !== null || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) { throw new UnauthorizedHttpException('Bearer'); }
            if (!in_array($user['role'], ['ADMIN','PASTOR','LEADER'], true)) { throw new AccessDeniedHttpException(); }
            return $operation($now);
        });
    }

    private function lockSchedule(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ministry_schedules WHERE id = ? FOR UPDATE', [$id]);
        return $row === false ? throw new NotFoundHttpException() : $row;
    }

    private function lockMinistries(int $actorId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
        sort($ids);
        foreach ($ids as $id) {
            if ($this->db->fetchOne('SELECT id FROM ministries WHERE id = ? FOR UPDATE', [$id]) === false) { throw new NotFoundHttpException(); }
        }
        foreach ($ids as $id) { $this->db->fetchOne('SELECT id FROM user_ministries WHERE user_id = ? AND ministry_id = ? FOR UPDATE', [$actorId, $id]); }
    }

    private function requireOrigin(int $actorId, ?int $ministry): void
    {
        if (!$this->policy->canManageContent($actorId, $ministry)) { throw new NotFoundHttpException(); }
    }

    private function requireDestination(int $actorId, ?int $ministry, bool $requireActive): void
    {
        if (!$this->policy->canManageContent($actorId, $ministry)) { throw new AccessDeniedHttpException(); }
        if ($requireActive && $ministry !== null && $this->db->fetchOne('SELECT status FROM ministries WHERE id = ?', [$ministry]) !== 'ACTIVE') { throw new ConflictHttpException(); }
    }

    private function audit(int $actorId, int $id, string $action, array $metadata, string $now): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actorId, 'entity_type' => 'ministry_schedules', 'entity_id' => $id,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'request_id' => bin2hex(random_bytes(16)), 'created_at' => $now]);
    }
}
