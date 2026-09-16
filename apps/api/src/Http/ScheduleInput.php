<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;

#[Exclude]
final class ScheduleInput
{
    public const FIELDS = ['title', 'description', 'starts_at', 'ends_at', 'ministry_id', 'visibility'];

    public static function data(array $data, bool $create = false): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== []) { throw new InputValidationException('body', 'body'); }
        $result = EventInput::data($data, $create);
        unset($result['location'], $result['address']);
        return $result;
    }

    public static function query(Request $request, bool $administrative = false): array
    {
        return EventInput::query($request, $administrative);
    }

    public static function audience(?int $ministryId, string $visibility): void
    {
        EventInput::audience($ministryId, $visibility);
    }

    public static function interval(string $start, ?string $end): void
    {
        EventInput::interval($start, $end);
    }
}
