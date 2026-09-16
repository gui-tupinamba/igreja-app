<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\UserView;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class ProfileReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function own(int $actorId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, name, email, phone, birth_date, role, status, created_at, updated_at FROM users WHERE id = ? AND status = 'ACTIVE'",
            [$actorId],
        );
        if ($row === false) {
            throw new UnauthorizedHttpException('Bearer');
        }
        $memberships = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.id, m.name, (um.is_leader AND u.role IN ('ADMIN', 'PASTOR', 'LEADER')) AS leads
            FROM user_ministries um
            JOIN ministries m ON m.id = um.ministry_id AND m.status = 'ACTIVE'
            JOIN users u ON u.id = um.user_id AND u.status = 'ACTIVE'
            WHERE um.user_id = ? AND um.status = 'ACTIVE'
            ORDER BY m.name, m.id
            SQL, [$actorId]);
        $ministries = [];
        $ledMinistries = [];
        foreach ($memberships as $membership) {
            $item = ['id' => (int) $membership['id'], 'name' => $membership['name']];
            $ministries[] = $item;
            if ($membership['leads']) {
                $ledMinistries[] = $item;
            }
        }
        return ['user' => UserView::record($row), 'ministries' => $ministries, 'led_ministries' => $ledMinistries];
    }
}
