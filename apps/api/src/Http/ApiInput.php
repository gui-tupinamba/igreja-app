<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

#[Exclude]
final class ApiInput
{
    public static function jsonObject(Request $request, array $allowedFields, int $maxBytes = 8192): array
    {
        if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0])) !== 'application/json') {
            throw new BadRequestHttpException();
        }
        $raw = $request->getContent();
        if (strlen($raw) > $maxBytes) {
            throw new BadRequestHttpException();
        }
        try {
            $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException();
        }
        if (!$body instanceof \stdClass) {
            throw new BadRequestHttpException();
        }
        $body = (array) $body;
        if (array_diff(array_keys($body), $allowedFields) !== []) {
            throw new UnprocessableEntityHttpException();
        }

        return $body;
    }

    /** @return array{page: int, limit: int, offset: int} */
    public static function pagination(Request $request, array $extraAllowed = [], int $maxOffset = 2147483647): array
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['page', 'limit', ...$extraAllowed]) !== []) {
            throw new UnprocessableEntityHttpException();
        }
        $values = [];
        foreach (['page' => '1', 'limit' => '20'] as $key => $default) {
            $value = $query[$key] ?? $default;
            if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int) $value > 2147483647) {
                throw new UnprocessableEntityHttpException();
            }
            $values[$key] = (int) $value;
        }
        $offset = ($values['page'] - 1) * $values['limit'];
        if ($values['limit'] > 100 || $offset > $maxOffset) {
            throw new UnprocessableEntityHttpException();
        }

        return [...$values, 'offset' => $offset];
    }

    public static function id(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int) $value > 2147483647) {
            throw new NotFoundHttpException();
        }

        return (int) $value;
    }
}
