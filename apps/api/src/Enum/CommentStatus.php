<?php

declare(strict_types=1);

namespace App\Enum;

enum CommentStatus: string
{
    case VISIBLE = 'VISIBLE';
    case HIDDEN = 'HIDDEN';
    case DELETED = 'DELETED';
}
