<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class EventView
{
    public static function data(
        array $row,
        array $images = [],
    ): array {
        $result = [
            'id' => (int) $row['id'],
            'created_by' => (int) $row['created_by'],

            'ministry_id' =>
                $row['ministry_id'] === null
                    ? null
                    : (int) $row['ministry_id'],
        ];

        foreach (
            [
                'title',
                'description',
                'location',
                'address',
                'visibility',
                'status',
            ] as $field
        ) {
            $result[$field] = $row[$field];
        }

        foreach (
            [
                'starts_at',
                'ends_at',
                'created_at',
                'updated_at',
            ] as $field
        ) {
            $result[$field] =
                $row[$field] === null
                    ? null
                    : (
                        new \DateTimeImmutable(
                            $row[$field]
                        )
                    )
                        ->setTimezone(
                            new \DateTimeZone('UTC')
                        )
                        ->format(
                            'Y-m-d\TH:i:s\Z'
                        );
        }

        $result['images'] = $images;

        return $result;
    }
}