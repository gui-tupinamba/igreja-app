<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Http\UserView;
use App\Security\Authorization\AccessPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AdminUserReader
{
    private const SCOPE = "EXISTS (SELECT 1 FROM users actor WHERE actor.id = :actor AND actor.status = 'ACTIVE' AND (actor.role = 'ADMIN' OR (actor.role = 'PASTOR' AND u.role <> 'ADMIN')))";
    private const FIELDS = 'u.id, u.name, u.email, u.role, u.status';

    public function __construct(private Connection $connection, private AccessPolicy $policy)
    {
    }

    public function list(int $actorId, int $page, int $limit, ?UserRole $role = null, ?UserStatus $status = null): array
    {
        $this->assertManager($actorId);
        $where = self::SCOPE;
        $parameters = ['actor' => $actorId];
        $types = ['actor' => ParameterType::INTEGER];
        foreach (['role' => $role?->value, 'status' => $status?->value] as $field => $value) {
            if ($value !== null) {
                $where .= ' AND u.'.$field.' = :'.$field;
                $parameters[$field] = $value;
                $types[$field] = ParameterType::STRING;
            }
        }
        $total = (int) $this->connection->fetchOne('SELECT count(*) FROM users u WHERE '.$where, $parameters, $types);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::FIELDS.' FROM users u WHERE '.$where.' ORDER BY u.id ASC LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => ($page - 1) * $limit],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return ['items' => array_map($this->serialize(...), $rows), 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total]];
    }

    public function detail(int $actorId, int $id): array
    {
        $this->assertManager($actorId);
        $row = $this->connection->fetchAssociative('SELECT '.self::FIELDS.', u.phone, u.birth_date, u.created_at, u.updated_at FROM users u WHERE '.self::SCOPE.' AND u.id = :id', ['actor' => $actorId, 'id' => $id]);
        if ($row === false) {
            throw new NotFoundHttpException();
        }

        return UserView::record($row);
    }

    private function assertManager(int $actorId): void
    {
        if (!$this->policy->canManageUsers($actorId)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function serialize(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role'], 'status' => $row['status']];
    }
}
