<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\CommentView;
use App\Security\Authorization\ContentReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CommentDirectory
{
    public function __construct(private Connection $db) {}

    public function list(int $actorId, int $postId, int $page, int $limit, bool $administrative = false): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid pagination.'); }
        if ($administrative && $this->db->fetchOne("SELECT 1 FROM users WHERE id = ? AND status = 'ACTIVE' AND role IN ('ADMIN','PASTOR')", [$actorId]) === false) { throw new AccessDeniedHttpException(); }
        $scope = $administrative
            ? "EXISTS (SELECT 1 FROM users a WHERE a.id = :actor_id AND a.status = 'ACTIVE' AND a.role IN ('ADMIN','PASTOR'))"
            : ContentReadScope::predicate('posts', 'p');
        $status = $administrative ? "c.status IN ('VISIBLE','HIDDEN')" : "c.status = 'VISIBLE'";
        // Parent access, filtered count and page use one database snapshot, including empty pages.
        $rows = $this->db->fetchAllAssociative("WITH parent AS (
                SELECT p.id FROM posts p WHERE p.id = :post_id AND ($scope)
            ), authorized AS (
                SELECT c.* FROM comments c JOIN parent p ON p.id = c.post_id WHERE $status
            ), page_rows AS (SELECT * FROM authorized ORDER BY created_at, id LIMIT :limit OFFSET :offset)
            SELECT c.*, totals.total, EXISTS (SELECT 1 FROM parent) AS parent_allowed
            FROM (SELECT count(*) AS total FROM authorized) totals LEFT JOIN page_rows c ON TRUE ORDER BY c.created_at, c.id",
            ['actor_id' => $actorId, 'post_id' => $postId, 'limit' => $limit, 'offset' => ($page - 1) * $limit],
            ['actor_id' => ParameterType::INTEGER, 'post_id' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);
        if (!$rows[0]['parent_allowed']) { throw new NotFoundHttpException(); }
        $items = [];
        foreach ($rows as $row) { if ($row['id'] !== null) { $items[] = CommentView::data($row); } }
        return ['items' => $items, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $rows[0]['total']]];
    }
}
