<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\MinistryView;
use App\Security\Authorization\AccessPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MinistryDirectory
{
    private const ACTIVE_ACTOR = "EXISTS (SELECT 1 FROM users a WHERE a.id = :actor AND a.status = 'ACTIVE')";
    private const GLOBAL_ACTOR = "EXISTS (SELECT 1 FROM users a WHERE a.id = :actor AND a.status = 'ACTIVE' AND a.role IN ('ADMIN','PASTOR'))";
    private const MEMBERS_SCOPE = "EXISTS (SELECT 1 FROM users a WHERE a.id = :actor AND a.status = 'ACTIVE' AND
        (a.role IN ('ADMIN','PASTOR') OR (a.role = 'LEADER' AND m.status = 'ACTIVE' AND um.status = 'ACTIVE' AND u.status = 'ACTIVE'
        AND EXISTS (SELECT 1 FROM user_ministries leader WHERE leader.user_id = a.id AND leader.ministry_id = m.id AND leader.status = 'ACTIVE' AND leader.is_leader = TRUE))))";

    public function __construct(private Connection $db, private AccessPolicy $policy)
    {
    }

    public function list(int $actorId, int $page, int $limit, bool $administrative = false, ?string $status = null): array
    {
        if ($administrative && !$this->policy->canManageMinistries($actorId)) { throw new AccessDeniedHttpException(); }
        $where = $administrative ? self::GLOBAL_ACTOR : self::ACTIVE_ACTOR." AND m.status = 'ACTIVE'";
        $parameters = ['actor' => $actorId];
        if ($status !== null) { $where .= ' AND m.status = :status'; $parameters['status'] = $status; }
        return $this->page('ministries m', 'm.*', $where, 'name, id', $parameters, $page, $limit, MinistryView::ministry(...));
    }

    public function detail(int $actorId, int $id, bool $administrative = false): array
    {
        if ($administrative && !$this->policy->canManageMinistries($actorId)) { throw new AccessDeniedHttpException(); }
        $where = $administrative ? self::GLOBAL_ACTOR : self::ACTIVE_ACTOR." AND m.status = 'ACTIVE'";
        $row = $this->db->fetchAssociative('SELECT m.* FROM ministries m WHERE '.$where.' AND m.id = :id', ['actor' => $actorId, 'id' => $id]);
        if ($row === false) { throw new NotFoundHttpException(); }
        return MinistryView::ministry($row);
    }

    public function members(int $actorId, int $id, int $page, int $limit, string $status = 'ACTIVE', bool $leadersOnly = false): array
    {
        if (!$this->policy->canManageContent($actorId, $id)) {
            // Active ministry metadata is public to the authenticated church, but its directory is restricted.
            if (!$this->policy->canReadMinistry($actorId, $id)) { throw new NotFoundHttpException(); }
            throw new AccessDeniedHttpException();
        }
        if ($status !== 'ACTIVE' && !$this->policy->canManageMinistries($actorId)) { throw new AccessDeniedHttpException(); }
        $where = self::MEMBERS_SCOPE.' AND m.id = :ministry AND um.status = :status';
        if ($leadersOnly) {
            $where .= " AND um.is_leader = TRUE AND u.status = 'ACTIVE' AND u.role IN ('ADMIN','PASTOR','LEADER') AND m.status = 'ACTIVE'";
        }
        return $this->page('user_ministries um JOIN ministries m ON m.id = um.ministry_id JOIN users u ON u.id = um.user_id',
            'um.*, u.name, u.role, u.status AS user_status', $where, 'name, user_id',
            ['actor' => $actorId, 'ministry' => $id, 'status' => $status], $page, $limit,
            static fn (array $row): array => [...MinistryView::membership($row), 'name' => $row['name'], 'role' => $row['role'], 'user_status' => $row['user_status']]);
    }

    private function page(string $from, string $fields, string $where, string $order, array $parameters, int $page, int $limit, callable $serialize): array
    {
        // One statement keeps count and results in the same database snapshot.
        $sql = 'WITH authorized AS (SELECT '.$fields.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$order.'), '
            .'total AS (SELECT count(*) AS count FROM authorized), '
            .'paged AS (SELECT * FROM authorized ORDER BY '.$order.' LIMIT :limit OFFSET :offset) '
            ."SELECT total.count AS total, COALESCE((SELECT json_agg(paged ORDER BY ".$order.") FROM paged), '[]'::json) AS items FROM total";
        $types = [];
        foreach ($parameters as $key => $value) { $types[$key] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING; }
        $row = $this->db->fetchAssociative($sql, [...$parameters, 'limit' => $limit, 'offset' => ($page - 1) * $limit],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);
        return ['items' => array_map($serialize, json_decode($row['items'], true, flags: JSON_THROW_ON_ERROR)),
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $row['total']]];
    }
}
