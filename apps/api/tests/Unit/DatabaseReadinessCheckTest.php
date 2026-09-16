<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Health\DatabaseReadinessCheck;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DatabaseReadinessCheckTest extends TestCase
{
    public function testSuccessfulDatabaseProbe(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->with('SELECT 1')->willReturn(1);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertTrue((new DatabaseReadinessCheck($connection, $logger))->isReady());
    }

    public function testDatabaseFailureDoesNotLogConnectionCredentials(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->with('SELECT 1')
            ->willThrowException(new \RuntimeException('postgresql://user:secret@private-host/db'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Database readiness check failed.',
            ['exception_type' => \RuntimeException::class],
        );

        self::assertFalse((new DatabaseReadinessCheck($connection, $logger))->isReady());
    }
}
