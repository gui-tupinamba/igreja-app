<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Guard;
use App\Enum\MinistryStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MinistryInput
{
    public const FIELDS = ['name', 'slug', 'description', 'status'];

    public static function ministry(array $data, bool $create = false): array
    {
        if ($data === [] || array_diff(array_keys($data), self::FIELDS) !== []) {
            throw new InputValidationException('body', 'body');
        }
        if ($create) {
            $data = ['name' => null, 'slug' => null, 'description' => null, 'status' => 'ACTIVE', ...$data];
        }
        $result = [];
        foreach ($data as $key => $value) {
            if ($key === 'status') {
                $result[$key] = self::status($value);
                continue;
            }
            if ($key === 'description' && $value === null) {
                $result[$key] = null;
                continue;
            }
            try {
                if (!is_string($value) || preg_match($key === 'description' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new \InvalidArgumentException();
                }
                $result[$key] = $key === 'description' ? Guard::optionalText($value, 10000)
                    : Guard::text($value, $key === 'name' ? 120 : 160);
                if ($key === 'slug' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $result[$key]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            } catch (\InvalidArgumentException) {
                throw new InputValidationException($key, $key);
            }
        }
        return $result;
    }

    public static function status(mixed $value): string
    {
        if (!is_string($value) || MinistryStatus::tryFrom($value) === null) {
            throw new InputValidationException('status', 'ministry_status');
        }
        return $value;
    }

    public static function member(array $data, bool $leadership = false): array
    {
        if (array_diff(array_keys($data), $leadership ? ['user_id', 'promote_to_leader'] : ['user_id']) !== []) {
            throw new InputValidationException('body', 'body');
        }
        $id = $data['user_id'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 2147483647) {
            throw new InputValidationException('user_id', 'user_id');
        }
        $promote = array_key_exists('promote_to_leader', $data) ? $data['promote_to_leader'] : false;
        if (!is_bool($promote)) {
            throw new InputValidationException('promote_to_leader', 'promote_to_leader');
        }
        return ['user_id' => $id, 'promote_to_leader' => $promote];
    }
}
