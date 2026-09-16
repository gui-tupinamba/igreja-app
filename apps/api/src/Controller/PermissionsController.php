<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Authorization\AccessPolicy;
use App\Security\CurrentActor;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PermissionsController
{
    public function __construct(private CurrentActor $actor, private AccessPolicy $policy, private Connection $connection)
    {
    }

    #[Route('/api/auth/permissions', name: 'api_auth_permissions', methods: ['GET'])]
    public function permissions(): JsonResponse
    {
        $id = $this->actor->get()->userId;
        $ministries = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.id, m.name FROM ministries m
            INNER JOIN user_ministries um ON um.ministry_id = m.id
            INNER JOIN users u ON u.id = um.user_id
            WHERE u.id = ? AND u.status = 'ACTIVE' AND u.role IN ('ADMIN', 'PASTOR', 'LEADER')
              AND m.status = 'ACTIVE' AND um.status = 'ACTIVE' AND um.is_leader = TRUE
            ORDER BY m.id
            SQL, [$id]);

        return new JsonResponse(['permissions' => [
            'manage_users' => $this->policy->canManageUsers($id),
            'manage_ministries' => $this->policy->canManageMinistries($id),
            'manage_settings' => $this->policy->canManageCriticalSettings($id),
            'led_ministries' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $ministries),
        ]], headers: ['Cache-Control' => 'no-store']);
    }
}
