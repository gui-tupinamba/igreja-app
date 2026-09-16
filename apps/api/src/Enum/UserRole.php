<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case PASTOR = 'PASTOR';
    case LEADER = 'LEADER';
    case MEMBER = 'MEMBER';
}
