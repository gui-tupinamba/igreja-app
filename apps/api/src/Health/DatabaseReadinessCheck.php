<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class DatabaseReadinessCheck implements ReadinessCheckInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function isReady(): bool
    {
        try {
            return (int) $this->connection->fetchOne('SELECT 1') === 1;
        } catch (\Throwable $exception) {
            $this->logger->warning('Database readiness check failed.', [
                'exception_type' => $exception::class,
            ]);

            return false;
        }
    }
}
