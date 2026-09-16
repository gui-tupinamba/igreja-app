<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Guard;
use App\Enum\ContentVisibility;
use App\Enum\EventStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;

#[Exclude]
final class EventInput
{
    public const FIELDS = ['title', 'description', 'location', 'address', 'starts_at', 'ends_at', 'ministry_id', 'visibility'];

    public static function data(array $data, bool $create = false): array
    {
        if ($data === [] || array_diff(array_keys($data), self::FIELDS) !== []) { throw new InputValidationException('body', 'body'); }
        if ($create) { $data = ['title' => null, 'description' => null, 'location' => null, 'address' => null, 'starts_at' => null, 'ends_at' => null, 'ministry_id' => null, 'visibility' => 'PUBLIC', ...$data]; }
        $result = [];
        foreach ($data as $field => $value) {
            $result[$field] = match ($field) {
                'title' => self::text($value, 180, 'title'),
                'description' => self::optionalText($value, 50000, 'description', true),
                'location' => self::optionalText($value, 180, 'location'),
                'address' => self::optionalText($value, 500, 'address'),
                'starts_at' => self::date($value, 'starts_at'),
                'ends_at' => $value === null ? null : self::date($value, 'ends_at'),
                'ministry_id' => self::ministryId($value),
                'visibility' => self::visibility($value),
            };
        }
        return $result;
    }

    public static function query(Request $request, bool $administrative = false): array
    {
        $page = ApiInput::pagination($request, ['ministry_id', 'visibility', 'q', 'status', 'from', 'to']);
        $query = $request->query->all();
        $filters = [];
        if (array_key_exists('ministry_id', $query)) {
            $value = $query['ministry_id'];
            if ($value === 'null') { $filters['ministry_id'] = null; }
            elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int) $value <= 2147483647) { $filters['ministry_id'] = (int) $value; }
            else { throw new InputValidationException('ministry_id', 'ministry_id'); }
        }
        if (array_key_exists('visibility', $query)) { $filters['visibility'] = self::visibility($query['visibility']); }
        if (array_key_exists('status', $query)) {
            $value = $query['status'];
            if (!is_string($value) || EventStatus::tryFrom($value) === null || (!$administrative && !in_array($value, ['PUBLISHED','CANCELLED'], true))) { throw new InputValidationException('status', 'event_status'); }
            $filters['status'] = $value;
        }
        if (array_key_exists('q', $query)) { $filters['q'] = self::text($query['q'], 120, 'q'); }
        foreach (['from','to'] as $field) {
            if (array_key_exists($field, $query)) { $filters[$field] = self::date($query[$field], $field); }
        }
        if (isset($filters['from'], $filters['to']) && new \DateTimeImmutable($filters['to']) <= new \DateTimeImmutable($filters['from'])) { throw new InputValidationException('to', 'period'); }
        return [...$page, 'filters' => $filters];
    }

    public static function audience(?int $ministryId, string $visibility): void
    {
        if ($ministryId === null && $visibility === 'MINISTRY_MEMBERS') { throw new InputValidationException('ministry_id', 'private_ministry'); }
    }

    public static function interval(string $start, ?string $end): void
    {
        if ($end !== null && new \DateTimeImmutable($end) < new \DateTimeImmutable($start)) { throw new InputValidationException('ends_at', 'interval'); }
    }

    private static function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-](?:0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)$/D', $value) !== 1 || str_starts_with($value, '0000')) { throw new InputValidationException($field, 'datetime'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) { throw new InputValidationException($field, 'datetime'); }
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        if ((int) $date->format('Y') < 1 || (int) $date->format('Y') > 9999) { throw new InputValidationException($field, 'datetime'); }
        return $date->format('Y-m-d H:i:sP');
    }

    private static function optionalText(mixed $value, int $max, string $field, bool $multiline = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1)) { return null; }
        return self::text($value, $max, $field, $multiline);
    }

    private static function ministryId(mixed $value): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 1 || $value > 2147483647)) { throw new InputValidationException('ministry_id', 'ministry_id'); }
        return $value;
    }

    private static function visibility(mixed $value): string
    {
        if (!is_string($value) || ContentVisibility::tryFrom($value) === null) { throw new InputValidationException('visibility', 'visibility'); }
        return $value;
    }

    private static function text(mixed $value, int $max, string $field, bool $multiline = false): string
    {
        try {
            if (!is_string($value) || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 1) { throw new \InvalidArgumentException(); }
            return Guard::text($value, $max);
        } catch (\InvalidArgumentException) { throw new InputValidationException($field, $field === 'description' ? 'event_description' : $field); }
    }
}
