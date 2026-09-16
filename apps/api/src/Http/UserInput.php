<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Exception\AdminCreationException;
use App\Auth\InitialAdminCreator;
use App\Domain\Guard;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class UserInput
{
    public const CREATE_FIELDS = ['name', 'email', 'password', 'role', 'status', 'phone', 'birth_date'];
    public const PROFILE_FIELDS = ['name', 'email', 'phone', 'birth_date'];
    public const SELF_FIELDS = ['name', 'phone', 'birth_date'];

    public static function create(#[\SensitiveParameter] array $data): array
    {
        self::allow($data, self::CREATE_FIELDS);
        $role = $data['role'] ?? null;
        $status = $data['status'] ?? null;
        return [
            'name' => self::name($data['name'] ?? null),
            'email' => self::email($data['email'] ?? null),
            'password' => self::password($data['password'] ?? null),
            'role' => !array_key_exists('role', $data) ? UserRole::MEMBER
                : (is_string($role) ? (UserRole::tryFrom($role) ?? throw new InputValidationException('role', 'role')) : throw new InputValidationException('role', 'role')),
            'status' => !array_key_exists('status', $data) ? UserStatus::ACTIVE
                : (is_string($status) ? (UserStatus::tryFrom($status) ?? throw new InputValidationException('status', 'status')) : throw new InputValidationException('status', 'status')),
            'phone' => self::phone($data['phone'] ?? null),
            'birth_date' => self::birthDate($data['birth_date'] ?? null),
        ];
    }

    /** Omitted fields are preserved; null clears only optional fields. */
    public static function profile(array $data, bool $self = false): array
    {
        self::allow($data, $self ? self::SELF_FIELDS : self::PROFILE_FIELDS);
        $result = [];
        foreach ($data as $field => $value) {
            $result[$field] = match ($field) {
                'name' => self::name($value),
                'email' => self::email($value),
                'phone' => self::phone($value),
                'birth_date' => self::birthDate($value),
            };
        }
        return $result;
    }

    public static function password(#[\SensitiveParameter] mixed $value, string $field = 'password'): string
    {
        try {
            return InitialAdminCreator::validatePassword($value);
        } catch (AdminCreationException) {
            throw new InputValidationException($field, 'password');
        }
    }

    public static function currentPassword(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 72 || str_contains($value, "\0")) {
            throw new InputValidationException('current_password', 'current_password');
        }
        return $value;
    }

    private static function allow(array $data, array $fields): void
    {
        if ($data === [] || array_diff(array_keys($data), $fields) !== []) {
            throw new InputValidationException('body', 'body');
        }
    }

    private static function name(mixed $value): string
    {
        try {
            self::plainText($value);
            return InitialAdminCreator::validateName($value);
        } catch (AdminCreationException|\InvalidArgumentException) {
            throw new InputValidationException('name', 'name');
        }
    }

    private static function email(mixed $value): string
    {
        try {
            self::plainText($value);
            return InitialAdminCreator::validateEmail($value);
        } catch (AdminCreationException|\InvalidArgumentException) {
            throw new InputValidationException('email', 'email');
        }
    }

    private static function phone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            self::plainText($value);
            return Guard::optionalText($value, 30);
        } catch (\InvalidArgumentException) {
            throw new InputValidationException('phone', 'phone');
        }
    }

    private static function plainText(mixed $value): void
    {
        if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException();
        }
    }

    private static function birthDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 || substr($value, 0, 4) === '0000') {
            throw new InputValidationException('birth_date', 'birth_date');
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        if ($date === false || $date->format('Y-m-d') !== $value || $date > new DateTimeImmutable('today', $utc)) {
            throw new InputValidationException('birth_date', 'birth_date');
        }
        return $date;
    }
}
