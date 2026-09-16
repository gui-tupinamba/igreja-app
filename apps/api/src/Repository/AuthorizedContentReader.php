<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\Authorization\ContentReadScope;
use Doctrine\DBAL\Connection;

/** Read-only event and comment queries; posts use PostDirectory. */
final readonly class AuthorizedContentReader
{

    public function __construct(private Connection $connection)
    {
    }

    public function ministryEvent(int $actorId, int $ministryId, int $eventId): ?array
    {
        $scope = ContentReadScope::predicate('events', 'e');
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT e.id, e.title, e.description, e.location, e.address, e.starts_at,
                e.ends_at, e.visibility, e.status, e.ministry_id, e.created_by
            FROM events e WHERE e.id = :id AND e.ministry_id = :ministry_id AND ($scope)
            SQL, ['id' => $eventId, 'ministry_id' => $ministryId, 'actor_id' => $actorId]);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['ministry_id'] = (int) $row['ministry_id'];
        $row['created_by'] = (int) $row['created_by'];
        $row['starts_at'] = $this->timestamp($row['starts_at']);
        $row['ends_at'] = $row['ends_at'] === null ? null : $this->timestamp($row['ends_at']);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function postComment(int $actorId, int $postId, int $commentId): ?array
    {
        $scope = ContentReadScope::predicate('comments', 'c');
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT c.id, c.post_id, c.user_id, c.content, c.status, c.created_at, c.updated_at
            FROM comments c WHERE c.id = :id AND c.post_id = :post_id AND ($scope)
            SQL, ['id' => $commentId, 'post_id' => $postId, 'actor_id' => $actorId]);
        if ($row === false) {
            return null;
        }
        foreach (['id', 'post_id', 'user_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['created_at'] = $this->timestamp($row['created_at']);
        $row['updated_at'] = $this->timestamp($row['updated_at']);

        return $row;
    }

    private function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
