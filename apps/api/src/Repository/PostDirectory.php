<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\PostView;
use App\Security\Authorization\ContentReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class PostDirectory
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
        $scope = $administrative ? self::MANAGE_SCOPE : ContentReadScope::predicate('posts', 'p');
        $parameters = ['actor_id' => $actorId, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
        foreach (['ministry_id', 'visibility', 'status'] as $field) {
            if (!array_key_exists($field, $filters)) { continue; }
            if ($filters[$field] === null) { $scope .= ' AND p.'.$field.' IS NULL'; }
            else { $scope .= ' AND p.'.$field.' = :filter_'.$field; $parameters['filter_'.$field] = $filters[$field]; }
        }
        if (isset($filters['q'])) {
            // Literal substring search: %, _ and backslash are not SQL wildcards.
            $scope .= ' AND (strpos(lower(p.title), lower(:search)) > 0 OR strpos(lower(p.content), lower(:search)) > 0)';
            $parameters['search'] = $filters['q'];
        }
        $order = $administrative ? 'created_at DESC, id DESC' : 'published_at DESC, id DESC';
        $types = [];
        foreach ($parameters as $key => $value) { $types[$key] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING; }
        $rows = $this->db->fetchAllAssociative("WITH authorized AS (
                SELECT p.id, p.created_at, p.published_at FROM posts p WHERE ($scope)
            ), page_keys AS (SELECT * FROM authorized ORDER BY $order LIMIT :limit OFFSET :offset)
            SELECT p.*, totals.total FROM (SELECT count(*) AS total FROM authorized) totals
            LEFT JOIN page_keys k ON TRUE LEFT JOIN posts p ON p.id = k.id
            ORDER BY ".($administrative ? 'k.created_at DESC, k.id DESC' : 'k.published_at DESC, k.id DESC'), $parameters, $types);
        $items = [];
        foreach ($rows as $row) { if ($row['id'] !== null) { $items[] = PostView::data($row); } }
        return ['items' => $items, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $rows[0]['total']]];
    }

    public function detail(int $actorId, int $id, bool $administrative = false): ?array
    {
        if ($administrative) { $this->requireManager($actorId); }
        $scope = $administrative ? self::MANAGE_SCOPE : ContentReadScope::predicate('posts', 'p');
        $row = $this->db->fetchAssociative("SELECT p.* FROM posts p WHERE p.id = :id AND ($scope)", ['id' => $id, 'actor_id' => $actorId]);
        return $row === false ? null : PostView::data($row);
    }

    private function requireManager(int $actorId): void
    {
        if ($this->db->fetchOne("SELECT 1 FROM users WHERE id = ? AND status = 'ACTIVE' AND role IN ('ADMIN','PASTOR','LEADER')", [$actorId]) === false) { throw new AccessDeniedHttpException(); }
    }
}
