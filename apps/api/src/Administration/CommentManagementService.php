<?php

declare(strict_types=1);

namespace App\Administration;

use App\Http\CommentInput;
use App\Security\AuthenticatedActor;
use App\Security\Authorization\AccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class CommentManagementService
{
    public function __construct(private Connection $db, private AccessPolicy $policy) {}

    public function create(AuthenticatedActor $actor, int $postId, array $data): array
    {
        $content = CommentInput::content($data);
        return $this->transaction($actor, $postId, function (array $post, string $now) use ($actor, $postId, $content): array {
            $this->requireRead($actor->userId, $postId);
            if (!$post['comments_enabled']) { throw new ConflictHttpException(); }
            $row = $this->db->fetchAssociative("INSERT INTO comments (post_id,user_id,content,status,created_at,updated_at) VALUES (?,?,?,'VISIBLE',?,?) RETURNING *", [$postId, $actor->userId, $content, $now, $now]);
            $this->audit($actor->userId, (int) $row['id'], 'comment.created', ['post_id' => $postId, 'status' => 'VISIBLE'], $now);
            return $row;
        });
    }

    public function update(AuthenticatedActor $actor, int $postId, int $id, array $data): array
    {
        $content = CommentInput::content($data);
        return $this->transaction($actor, $postId, function (array $post, string $now) use ($actor, $postId, $id, $content): array {
            $row = $this->ownComment($actor->userId, $postId, $id);
            if ($row['content'] !== $content) {
                $this->db->update('comments', ['content' => $content, 'updated_at' => $now], ['id' => $id]);
                $this->audit($actor->userId, $id, 'comment.updated', ['post_id' => $postId, 'changed_fields' => ['content']], $now);
                $row = [...$row, 'content' => $content, 'updated_at' => $now];
            }
            return $row;
        });
    }

    public function delete(AuthenticatedActor $actor, int $postId, int $id): void
    {
        $this->transaction($actor, $postId, function (array $post, string $now) use ($actor, $postId, $id): array {
            $row = $this->ownComment($actor->userId, $postId, $id);
            return $this->changeStatus($actor->userId, $row, 'DELETED', $now);
        });
    }

    public function moderate(AuthenticatedActor $actor, int $postId, int $id, array $data): void
    {
        $status = CommentInput::status($data);
        $this->transaction($actor, $postId, function (array $post, string $now) use ($actor, $postId, $id, $status): array {
            $row = $this->lockComment($postId, $id);
            if (!$this->policy->canModerateComment($actor->userId, $id)) { throw new NotFoundHttpException(); }
            return $this->changeStatus($actor->userId, $row, $status, $now);
        }, true);
    }

    private function ownComment(int $actorId, int $postId, int $id): array
    {
        $this->requireRead($actorId, $postId);
        $row = $this->lockComment($postId, $id);
        if ($row['status'] !== 'VISIBLE') { throw new NotFoundHttpException(); }
        if ((int) $row['user_id'] !== $actorId) { throw new AccessDeniedHttpException(); }
        return $row;
    }

    private function changeStatus(int $actorId, array $row, string $status, string $now): array
    {
        if ($row['status'] !== $status) {
            $this->db->update('comments', ['status' => $status, 'updated_at' => $now], ['id' => $row['id']]);
            $this->audit($actorId, (int) $row['id'], 'comment.status_changed', ['post_id' => (int) $row['post_id'], 'before' => $row['status'], 'after' => $status], $now);
        }
        return [...$row, 'status' => $status];
    }

    private function requireRead(int $actorId, int $postId): void
    {
        if (!$this->policy->canReadPost($actorId, $postId)) { throw new NotFoundHttpException(); }
    }

    private function lockComment(int $postId, int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM comments WHERE id = ? AND post_id = ? FOR UPDATE', [$id, $postId]);
        return $row === false || $row['status'] === 'DELETED' ? throw new NotFoundHttpException() : $row;
    }

    private function transaction(AuthenticatedActor $actor, int $postId, callable $operation, bool $globalOnly = false): array
    {
        if ($this->db->isTransactionActive()) { throw new \LogicException('Comment changes must own the outermost transaction.'); }
        return $this->db->transactional(function () use ($actor, $postId, $operation, $globalOnly): array {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
            $user = $this->db->fetchAssociative('SELECT role,status FROM users WHERE id = ? FOR UPDATE', [$actor->userId]);
            if ($user === false || $user['status'] !== 'ACTIVE') { throw new UnauthorizedHttpException('Bearer'); }
            $session = $this->db->fetchAssociative('SELECT revoked_at,expires_at FROM auth_sessions WHERE id = ? AND user_id = ? FOR UPDATE', [$actor->sessionId, $actor->userId]);
            $now = (string) $this->db->fetchOne("SELECT date_trunc('second',clock_timestamp())");
            if ($session === false || $session['revoked_at'] !== null || new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable($now)) { throw new UnauthorizedHttpException('Bearer'); }
            if ($globalOnly && !in_array($user['role'], ['ADMIN','PASTOR'], true)) { throw new AccessDeniedHttpException(); }
            $post = $this->db->fetchAssociative('SELECT * FROM posts WHERE id = ? FOR UPDATE', [$postId]);
            if ($post === false) { throw new NotFoundHttpException(); }
            if ($post['ministry_id'] !== null) {
                $this->db->fetchOne('SELECT id FROM ministries WHERE id = ? FOR UPDATE', [$post['ministry_id']]);
                $this->db->fetchOne('SELECT id FROM user_ministries WHERE ministry_id = ? AND user_id = ? FOR UPDATE', [$post['ministry_id'], $actor->userId]);
            }
            return $operation($post, $now);
        });
    }

    private function audit(int $actorId, int $id, string $action, array $metadata, string $now): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actorId, 'entity_type' => 'comments', 'entity_id' => $id,
            'action' => $action, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'request_id' => bin2hex(random_bytes(16)), 'created_at' => $now]);
    }
}
