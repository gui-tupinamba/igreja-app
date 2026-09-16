<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class PostView
{
    public static function data(array $row): array
    {
        $date = static fn (?string $value): ?string => $value === null ? null
            : (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        return ['id' => (int) $row['id'], 'title' => $row['title'], 'content' => $row['content'],
            'visibility' => $row['visibility'], 'status' => $row['status'],
            'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
            'published_at' => $date($row['published_at']), 'author_id' => (int) $row['author_id'],
            'comments_enabled' => (bool) $row['comments_enabled'],
            'created_at' => $date($row['created_at']), 'updated_at' => $date($row['updated_at'])];
    }
}
