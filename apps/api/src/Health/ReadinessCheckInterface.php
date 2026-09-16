<?php

declare(strict_types=1);

namespace App\Health;

interface ReadinessCheckInterface
{
    public function isReady(): bool;
}
