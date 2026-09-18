<?php

declare(strict_types=1);

namespace App\Administration;

use App\Enum\PostStatus;
use App\Http\PostInput;
use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class PostManagementService
{
    public function __construct(private Connection $db, private AccessPolicy $policy)
    {
    }

    public function create(AuthenticatedActor $actor, array $data): array
    {
        $data = PostInput::data($data, true);
        PostInput::audience($data['ministry_id'], $data['visibility']);
        return $this->transaction($actor, function (string $now) use ($actor, $data): array {
            $this->lockMinistries($actor->userId, [$data['ministry_id']]);
            $this->requireDestination($actor->userId, $data['ministry_id'], true);
            $row = $this->db->fetchAssociative("INSERT INTO posts
                (author_id,ministry_id,title,content,visibility,comments_enabled,status,created_at,updated_at)
                VALUES (?,?,?,?,?,?,'DRAFT',?,?) RETURNING *",
                [$actor->userId, $data['ministry_id'], $data['title'], $data['content'], $data['visibility'], $data['comments_enabled'] ? 'true' : 'false', $now, $now]);
            $this->audit($actor->userId, (int) $row['id'], 'post.created', ['ministry_id' => $data['ministry_id'], 'visibility' => $data['visibility'], 'status' => 'DRAFT'], $now);
            return $row;
        });
    }

    public function update(AuthenticatedActor $actor, int $id, array $data): array
    {
        $data = PostInput::data($data);
        return $this->transaction($actor, function (string $now) use ($actor, $id, $data): array {
            $post = $this->lockPost($id);
            $origin = $post['ministry_id'] === null ? null : (int) $post['ministry_id'];
            $destination = array_key_exists('ministry_id', $data) ? $data['ministry_id'] : $origin;
            $this->lockMinistries($actor->userId, [$origin, $destination]);
            $this->requireOrigin($actor->userId, $origin);
            PostInput::audience($destination, $data['visibility'] ?? $post['visibility']);
            $this->requireDestination($actor->userId, $destination, $origin !== $destination);
            $changes = [];
            foreach ($data as $field => $value) {
                $old = $field === 'ministry_id' ? $origin : $post[$field];
                if ($old !== $value) { $changes[$field] = $value; }
            }
            if ($changes !== []) {
                $fields = array_keys($changes);
                if (isset($changes['comments_enabled'])) { $changes['comments_enabled'] = $changes['comments_enabled'] ? 'true' : 'false'; }
                $this->db->update('posts', [...$changes, 'updated_at' => $now], ['id' => $id]);
                $this->audit($actor->userId, $id, 'post.updated', ['changed_fields' => $fields,
                    'before' => ['ministry_id' => $origin, 'visibility' => $post['visibility']],
                    'after' => ['ministry_id' => $destination, 'visibility' => $data['visibility'] ?? $post['visibility']]], $now);
            }
            return $this->db->fetchAssociative('SELECT * FROM posts WHERE id = ?', [$id]);
        });
    }

    public function transition(AuthenticatedActor $actor, int $id, PostStatus $status): array
    {
        return $this->transaction($actor, function (string $now) use ($actor, $id, $status): array {
            $post = $this->lockPost($id);
            $ministry = $post['ministry_id'] === null ? null : (int) $post['ministry_id'];
            $this->lockMinistries($actor->userId, [$ministry]);
            $this->requireOrigin(
                $actor->userId,
                $ministry
            );

            if ($status === PostStatus::PUBLISHED) {
                $this->requirePublisher(
                    $actor->userId
                );

                $this->requireDestination(
                    $actor->userId,
                    $ministry,
                    true
                );
            }

            if ($post['status'] !== $status->value) {
                $changes = ['status' => $status->value, 'updated_at' => $now];
                if ($status === PostStatus::PUBLISHED && $post['published_at'] === null) { $changes['published_at'] = $now; }
                $this->db->update('posts', $changes, ['id' => $id]);
                $this->audit($actor->userId, $id, 'post.status_changed', ['before' => $post['status'], 'after' => $status->value], $now);
            }
            return $this->db->fetchAssociative('SELECT * FROM posts WHERE id = ?', [$id]);
        });
    }

    private function transaction(AuthenticatedActor $actor, callable $operation): array
    {
        if ($this->db->isTransactionActive()) { throw new \LogicException('Post changes must own the outermost transaction.'); }
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

    private function lockPost(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM posts WHERE id = ? FOR UPDATE', [$id]);
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

    private function requirePublisher(
        int $actorId,
    ): void {
        $actor = $this->policy->actor(
            $actorId
        );

        if (
            $actor === null
            ||
            $actor['status'] !== 'ACTIVE'
            ||
            !in_array(
                $actor['role'],
                [
                    'ADMIN',
                    'PASTOR',
                ],
                true,
            )
        ) {
            throw new AccessDeniedHttpException();
        }
    }

    private function audit(int $actorId, int $id, string $action, array $metadata, string $now): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actorId, 'entity_type' => 'posts', 'entity_id' => $id,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'request_id' => bin2hex(random_bytes(16)), 'created_at' => $now]);
    }
}
