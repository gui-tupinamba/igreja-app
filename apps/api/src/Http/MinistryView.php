<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MinistryView
{
    public static function ministry(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'slug' => $row['slug'],
            'description' => $row['description'], 'status' => $row['status'],
            'created_at' => self::date($row['created_at']), 'updated_at' => self::date($row['updated_at'])];
    }

    public static function membership(array $row): array
    {
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'ministry_id' => (int) $row['ministry_id'],
            'status' => $row['status'], 'is_leader' => (bool) $row['is_leader'],
            'joined_at' => self::date($row['joined_at']), 'left_at' => self::date($row['left_at'])];
    }

    private static function date(?string $date): ?string
    {
        return $date === null ? null : (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339);
    }
}
