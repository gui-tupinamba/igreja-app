<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduleStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case CANCELLED = 'CANCELLED';
    case ARCHIVED = 'ARCHIVED';
}
