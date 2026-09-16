<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\JsonExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class JsonExceptionSubscriberTest extends TestCase
{
    public function testUnexpectedFailureNeverExposesExceptionDetailsInResponseOrLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'API request failed.',
            ['status' => 500, 'exception_type' => \RuntimeException::class],
        );
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/ready?token=private-token'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Database password=private-secret'),
        );

        (new JsonExceptionSubscriber($logger))->onException($event);

        self::assertSame(500, $event->getResponse()->getStatusCode());
        self::assertSame([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Não foi possível concluir a solicitação.',
            ],
        ], json_decode($event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue($event->isPropagationStopped());
        self::assertTrue($event->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
}
