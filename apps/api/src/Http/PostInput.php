<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Guard;
use App\Enum\ContentVisibility;
use App\Enum\PostStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;

#[Exclude]
final class PostInput
{
    public const FIELDS = ['title', 'content', 'ministry_id', 'visibility', 'comments_enabled'];

    public static function data(array $data, bool $create = false): array
    {
        if ($data === [] || array_diff(array_keys($data), self::FIELDS) !== []) { throw new InputValidationException('body', 'body'); }
        if ($create) { $data = ['title' => null, 'content' => null, 'ministry_id' => null, 'visibility' => 'PUBLIC', 'comments_enabled' => true, ...$data]; }
        $result = [];
        foreach ($data as $field => $value) {
            $result[$field] = match ($field) {
                'title' => self::text($value, 180, 'title'),
                'content' => self::text($value, 50000, 'content', true),
                'ministry_id' => self::ministryId($value),
                'visibility' => self::visibility($value),
                'comments_enabled' => is_bool($value) ? $value : throw new InputValidationException('comments_enabled', 'comments_enabled'),
            };
        }
        return $result;
    }

    public static function query(Request $request, bool $administrative = false): array
    {
        // Preserve the feed's existing page range; PostgreSQL OFFSET accepts bigint.
        $page = ApiInput::pagination($request, ['ministry_id', 'visibility', 'q', ...($administrative ? ['status'] : [])], PHP_INT_MAX);
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
            if (!is_string($value) || PostStatus::tryFrom($value) === null) { throw new InputValidationException('status', 'post_status'); }
            $filters['status'] = $value;
        }
        if (array_key_exists('q', $query)) { $filters['q'] = self::text($query['q'], 120, 'q'); }
        return [...$page, 'filters' => $filters];
    }

    public static function audience(?int $ministryId, string $visibility): void
    {
        if ($ministryId === null && $visibility === 'MINISTRY_MEMBERS') { throw new InputValidationException('ministry_id', 'private_ministry'); }
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
        } catch (\InvalidArgumentException) { throw new InputValidationException($field, $field); }
    }
}
