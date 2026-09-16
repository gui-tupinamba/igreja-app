<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Guard
{
    public static function text(string $value, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException(sprintf('Text must contain between 1 and %d valid characters.', $maxLength));
        }

        return $value;
    }

    public static function optionalText(?string $value, int $maxLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::text($value, $maxLength);
    }

    public static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
