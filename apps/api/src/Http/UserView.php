<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class UserView
{
    public static function profile(User $user): array
    {
        return [
            'id' => $user->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(),
            'phone' => $user->getPhone(), 'birth_date' => $user->getBirthDate()?->format('Y-m-d'),
            'role' => $user->getRole()->value, 'status' => $user->getStatus()->value,
            'created_at' => $user->getCreatedAt()->format(DATE_RFC3339),
            'updated_at' => $user->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }

    public static function record(array $row): array
    {
        $utc = new DateTimeZone('UTC');
        return [
            'id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'],
            'phone' => $row['phone'], 'birth_date' => $row['birth_date'],
            'role' => $row['role'], 'status' => $row['status'],
            'created_at' => (new DateTimeImmutable($row['created_at']))->setTimezone($utc)->format(DATE_RFC3339),
            'updated_at' => (new DateTimeImmutable($row['updated_at']))->setTimezone($utc)->format(DATE_RFC3339),
        ];
    }
}
