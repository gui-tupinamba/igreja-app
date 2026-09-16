<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Guard;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class CommentInput
{
    public static function content(array $data): string
    {
        if (array_keys($data) !== ['content']) { throw new InputValidationException('body', 'body'); }
        try {
            $value = $data['content'];
            if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) { throw new \InvalidArgumentException(); }
            return Guard::text($value, 5000);
        } catch (\InvalidArgumentException) { throw new InputValidationException('content', 'comment_content'); }
    }

    public static function status(array $data): string
    {
        if (array_keys($data) !== ['status'] || !in_array($data['status'], ['VISIBLE', 'HIDDEN', 'DELETED'], true)) { throw new InputValidationException('status', 'comment_status'); }
        return $data['status'];
    }
}
