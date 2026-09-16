<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\UserRole;
use Doctrine\DBAL\Connection;

/** Always consults current database state; token roles and managed ORM objects are not authority. */
final readonly class AccessPolicy
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{id: int, role: string, status: string}|null */
    public function actor(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, role, status FROM users WHERE id = ?', [$id]);

        return $row === false ? null : ['id' => (int) $row['id'], 'role' => $row['role'], 'status' => $row['status']];
    }

    public function canManageUsers(int $actorId, ?int $targetId = null, ?UserRole $newRole = null): bool
    {
        $actor = $this->actor($actorId);
        if (!$this->isGlobalManager($actor)) {
            return false;
        }
        if ($actor['role'] === UserRole::PASTOR->value && $newRole === UserRole::ADMIN) {
            return false;
        }
        if ($targetId === null) {
            return true;
        }
        $target = $this->actor($targetId);

        return $target !== null && ($actor['role'] === UserRole::ADMIN->value || $target['role'] !== UserRole::ADMIN->value);
    }

    public function canManageMinistries(int $actorId): bool
    {
        return $this->isGlobalManager($this->actor($actorId));
    }

    public function canManageCriticalSettings(int $actorId): bool
    {
        $actor = $this->actor($actorId);

        return $actor !== null && $actor['status'] === 'ACTIVE' && $actor['role'] === UserRole::ADMIN->value;
    }

    public function canManageContent(int $actorId, ?int $ministryId): bool
    {
        $actor = $this->actor($actorId);
        if ($actor === null || $actor['status'] !== 'ACTIVE') {
            return false;
        }
        if ($ministryId === null) {
            return $this->isGlobalManager($actor);
        }
        $status = $this->connection->fetchOne('SELECT status FROM ministries WHERE id = ?', [$ministryId]);
        if ($status === false) {
            return false;
        }
        if ($this->isGlobalManager($actor)) {
            return true;
        }

        return $actor['role'] === UserRole::LEADER->value && $status === 'ACTIVE'
            && $this->connection->fetchOne(<<<'SQL'
                SELECT 1 FROM user_ministries
                WHERE user_id = ? AND ministry_id = ? AND status = 'ACTIVE' AND is_leader = TRUE
                SQL, [$actorId, $ministryId]) !== false;
    }

    public function canReadMinistry(int $actorId, int $ministryId): bool
    {
        return $this->canRead('ministries', $actorId, $ministryId);
    }

    public function canReadPost(int $actorId, int $postId): bool
    {
        return $this->canRead('posts', $actorId, $postId);
    }

    public function canReadEvent(int $actorId, int $eventId): bool
    {
        return $this->canRead('events', $actorId, $eventId);
    }

    public function canReadSchedule(int $actorId, int $scheduleId): bool
    {
        return $this->canRead('ministry_schedules', $actorId, $scheduleId);
    }

    public function canReadComment(int $actorId, int $commentId): bool
    {
        return $this->canRead('comments', $actorId, $commentId);
    }

    public function canManagePost(int $actorId, int $postId): bool
    {
        return $this->canManageExistingContent('posts', $actorId, $postId);
    }

    public function canManageEvent(int $actorId, int $eventId): bool
    {
        return $this->canManageExistingContent('events', $actorId, $eventId);
    }

    public function canManageSchedule(int $actorId, int $scheduleId): bool
    {
        return $this->canManageExistingContent('ministry_schedules', $actorId, $scheduleId);
    }

    public function canCommentOnPost(int $actorId, int $postId): bool
    {
        $scope = ContentReadScope::predicate('posts');

        return $this->connection->fetchOne("SELECT 1 FROM posts c WHERE c.id = :id AND c.comments_enabled = TRUE AND ($scope)", [
            'id' => $postId, 'actor_id' => $actorId,
        ]) !== false;
    }

    public function canEditComment(int $actorId, int $commentId): bool
    {
        // Closing new comments does not prevent an author correcting an existing visible comment.
        $scope = ContentReadScope::predicate('comments');

        return $this->connection->fetchOne("SELECT 1 FROM comments c WHERE c.id = :id AND c.user_id = :actor_id AND ($scope)", [
            'id' => $commentId, 'actor_id' => $actorId,
        ]) !== false;
    }

    public function canModerateComment(int $actorId, int $commentId): bool
    {
        if (!$this->isGlobalManager($this->actor($actorId))) {
            return false;
        }
        $postId = $this->connection->fetchOne("SELECT post_id FROM comments WHERE id = ? AND status <> 'DELETED'", [$commentId]);

        // Global managers may also maintain hidden comments on draft/archived/inactive-ministry posts.
        return $postId !== false && $this->canManagePost($actorId, (int) $postId);
    }

    private function canRead(string $table, int $actorId, int $id): bool
    {
        $scope = ContentReadScope::predicate($table);

        return $this->connection->fetchOne("SELECT 1 FROM $table c WHERE c.id = :id AND ($scope)", [
            'id' => $id, 'actor_id' => $actorId,
        ]) !== false;
    }

    private function canManageExistingContent(string $table, int $actorId, int $id): bool
    {
        // Table is supplied only by the three typed public entry points above.
        $content = $this->connection->fetchAssociative("SELECT ministry_id FROM $table WHERE id = ?", [$id]);

        return $content !== false && $this->canManageContent($actorId, $content['ministry_id'] === null ? null : (int) $content['ministry_id']);
    }

    /** @param array{id: int, role: string, status: string}|null $actor */
    private function isGlobalManager(?array $actor): bool
    {
        return $actor !== null && $actor['status'] === 'ACTIVE' && in_array($actor['role'], [UserRole::ADMIN->value, UserRole::PASTOR->value], true);
    }
}
