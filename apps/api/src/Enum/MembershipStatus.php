<?php

declare(strict_types=1);

namespace App\Enum;

enum MembershipStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
