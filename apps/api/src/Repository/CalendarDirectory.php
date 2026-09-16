<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EventView;
use App\Security\Authorization\ContentReadScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class CalendarDirectory
{
    public function __construct(private Connection $db) {}

    public function list(int $actorId, int $page, int $limit, array $filters = []): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid pagination.'); }
        $eventScope = ContentReadScope::predicate('events', 'e');
        $activityScope = ContentReadScope::predicate('ministry_schedules', 's');
        $parameters = ['actor_id'=>$actorId, 'limit'=>$limit, 'offset'=>($page - 1) * $limit];
        $conditions = [];
        foreach (['ministry_id','visibility','status'] as $field) {
            if (!array_key_exists($field, $filters)) { continue; }
            if ($filters[$field] === null) { $conditions[] = "$field IS NULL"; }
            else { $conditions[] = "$field = :filter_$field"; $parameters['filter_'.$field] = $filters[$field]; }
        }
        if (isset($filters['q'])) {
            $conditions[] = '(strpos(lower(title), lower(:search)) > 0 OR strpos(lower(description), lower(:search)) > 0)';
            $parameters['search'] = $filters['q'];
        }
        if (isset($filters['from'])) {
            $conditions[] = 'COALESCE(ends_at, starts_at) >= :from_date'; $parameters['from_date'] = $filters['from'];
        } else { $conditions[] = 'COALESCE(ends_at, starts_at) >= CURRENT_TIMESTAMP'; }
        if (isset($filters['to'])) { $conditions[] = 'starts_at < :to_date'; $parameters['to_date'] = $filters['to']; }
        $where = implode(' AND ', $conditions);
        $types = [];
        foreach ($parameters as $key=>$value) { $types[$key] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING; }
        // Independent scopes precede the union, filters, count and global pagination.
        // IDs belong to their source table; kind + id is the composite public identity.
        $rows = $this->db->fetchAllAssociative("WITH readable AS (
            SELECT 'EVENT' AS kind, e.id,e.created_by,e.ministry_id,e.title,e.description,e.location,e.address,
                e.starts_at,e.ends_at,e.visibility,e.status,e.created_at,e.updated_at FROM events e WHERE ($eventScope)
            UNION ALL
            SELECT 'ACTIVITY' AS kind, s.id,s.created_by,s.ministry_id,s.title,s.description,NULL AS location,NULL AS address,
                s.starts_at,s.ends_at,s.visibility,s.status,s.created_at,s.updated_at FROM ministry_schedules s WHERE ($activityScope)
        ), authorized AS (SELECT * FROM readable WHERE $where),
        page_rows AS (SELECT * FROM authorized ORDER BY starts_at,kind,id LIMIT :limit OFFSET :offset)
        SELECT c.*, totals.total FROM (SELECT count(*) AS total FROM authorized) totals
        LEFT JOIN page_rows c ON TRUE ORDER BY c.starts_at,c.kind,c.id", $parameters, $types);
        $items = [];
        foreach ($rows as $row) { if ($row['id'] !== null) { $items[] = ['kind'=>$row['kind'], ...EventView::data($row)]; } }
        return ['items'=>$items, 'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int) $rows[0]['total']]];
    }
}
