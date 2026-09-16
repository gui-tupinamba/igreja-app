<?php

declare(strict_types=1);

namespace App\Enum;

enum ContentVisibility: string
{
    case PUBLIC = 'PUBLIC';
    case MINISTRY_MEMBERS = 'MINISTRY_MEMBERS';
}
