<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CommentView
{
    public static function data(array $row): array
    {
        $result = [];
        foreach (['id', 'post_id', 'user_id'] as $field) { $result[$field] = (int) $row[$field]; }
        foreach (['content', 'status'] as $field) { $result[$field] = $row[$field]; }
        foreach (['created_at', 'updated_at'] as $field) { $result[$field] = (new \DateTimeImmutable($row[$field]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
        return $result;
    }
}
