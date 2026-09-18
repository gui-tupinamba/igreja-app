<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EventView;
use App\Security\Authorization\ContentReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class EventDirectory
{
    private const MANAGE_SCOPE = "EXISTS (SELECT 1 FROM users a WHERE a.id = :actor_id AND a.status = 'ACTIVE'
        AND (a.role IN ('ADMIN','PASTOR') OR (a.role = 'LEADER' AND EXISTS (
            SELECT 1 FROM user_ministries um JOIN ministries m ON m.id = um.ministry_id
            WHERE um.user_id = a.id AND um.ministry_id = p.ministry_id
            AND um.status = 'ACTIVE' AND um.is_leader = TRUE AND m.status = 'ACTIVE'))))";

    public function __construct(private Connection $db)
    {
    }

    public function list(int $actorId, int $page, int $limit, array $filters = [], bool $administrative = false): array
    {
        if ($administrative) { $this->requireManager($actorId); }
        if ($page < 1 || $page > 2147483647 || $limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid pagination.'); }
        $scope = $administrative ? self::MANAGE_SCOPE : ContentReadScope::predicate('events', 'p');
        $parameters = ['actor_id' => $actorId, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
        foreach (['ministry_id', 'visibility', 'status'] as $field) {
            if (!array_key_exists($field, $filters)) { continue; }
            if ($filters[$field] === null) { $scope .= ' AND p.'.$field.' IS NULL'; }
            else { $scope .= ' AND p.'.$field.' = :filter_'.$field; $parameters['filter_'.$field] = $filters[$field]; }
        }
        if (isset($filters['q'])) {
            // Literal substring search: %, _ and backslash are not SQL wildcards.
            $scope .= ' AND (strpos(lower(p.title), lower(:search)) > 0 OR strpos(lower(p.description), lower(:search)) > 0)';
            $parameters['search'] = $filters['q'];
        }
        if (isset($filters['from'])) {
            $scope .= ' AND COALESCE(p.ends_at, p.starts_at) >= :from_date';
            $parameters['from_date'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $scope .= ' AND p.starts_at < :to_date';
            $parameters['to_date'] = $filters['to'];
        }
        $order = $administrative ? 'created_at DESC, id DESC' : 'starts_at ASC, id ASC';
        $types = [];
        foreach ($parameters as $key => $value) { $types[$key] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING; }
        $rows = $this->db->fetchAllAssociative("WITH authorized AS (
                SELECT p.id, p.created_at, p.starts_at FROM events p WHERE ($scope)
            ), page_keys AS (SELECT * FROM authorized ORDER BY $order LIMIT :limit OFFSET :offset)
            SELECT p.*, totals.total FROM (SELECT count(*) AS total FROM authorized) totals
            LEFT JOIN page_keys k ON TRUE LEFT JOIN events p ON p.id = k.id
            ORDER BY ".($administrative ? 'k.created_at DESC, k.id DESC' : 'k.starts_at ASC, k.id ASC'), $parameters, $types);
        $eventRows = [];
        $eventIds = [];

        foreach ($rows as $row) {
            if ($row['id'] === null) {
                continue;
            }

            $eventRows[] = $row;
            $eventIds[] = (int) $row['id'];
        }

        $imagesByEvent =
            $this->imagesForEvents($eventIds);

        $items = [];

        foreach ($eventRows as $row) {
            $eventId = (int) $row['id'];

            $items[] = EventView::data(
                $row,
                $imagesByEvent[$eventId] ?? [],
            );
        }

        return ['items' => $items, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $rows[0]['total']]];
            }

    public function detail(int $actorId, int $id, bool $administrative = false): ?array
    {
        if ($administrative) { $this->requireManager($actorId); }
        $scope = $administrative ? self::MANAGE_SCOPE : ContentReadScope::predicate('events', 'p');

        $row = $this->db->fetchAssociative(
            "SELECT p.*
            FROM events p
            WHERE p.id = :id
            AND ($scope)",
            [
                'id' => $id,
                'actor_id' => $actorId,
            ],
        );

        if ($row === false) {
            return null;
        }

        $eventId = (int) $row['id'];

        $imagesByEvent =
            $this->imagesForEvents([
                $eventId,
            ]);

        return EventView::data(
            $row,
            $imagesByEvent[$eventId] ?? [],
        );
    }

    private function imagesForEvents(
        array $eventIds,
    ): array {
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(
                0,
                count($eventIds),
                '?',
            )
        );

        $rows = $this->db->fetchAllAssociative(
            <<<SQL
            SELECT
                id,
                event_id,
                mime_type,
                size,
                position
            FROM event_images
            WHERE event_id IN ($placeholders)
            ORDER BY
                event_id,
                position,
                id
            SQL,
            $eventIds,
        );

        $images = [];

        foreach ($rows as $row) {
            $eventId =
                (int) $row['event_id'];

            $imageId =
                (int) $row['id'];

            $baseUrl =
                "/events/{$eventId}/images/{$imageId}";

            $images[$eventId][] = [
                'id' =>
                    $imageId,

                'position' =>
                    (int) $row['position'],

                'mime_type' =>
                    $row['mime_type'],

                'size' =>
                    (int) $row['size'],

                'url' =>
                    $baseUrl,

                'full_url' =>
                    $baseUrl.'/full',

                'detail_url' =>
                    $baseUrl.'/detail',

                'feed_url' =>
                    $baseUrl.'/feed',
            ];
        }

        return $images;
    }

    private function requireManager(int $actorId): void
    {
        if ($this->db->fetchOne("SELECT 1 FROM users WHERE id = ? AND status = 'ACTIVE' AND role IN ('ADMIN','PASTOR','LEADER')", [$actorId]) === false) { throw new AccessDeniedHttpException(); }
    }
}
