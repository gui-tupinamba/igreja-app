<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Health\ReadinessCheckInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthHeadWorksWithoutCheckingDatabase(): void
    {
        $client = self::createClient();
        $readiness = $this->createMock(ReadinessCheckInterface::class);
        $readiness->expects(self::never())->method('isReady');
        self::getContainer()->set(ReadinessCheckInterface::class, $readiness);

        $client->request('HEAD', '/api/health');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('', $client->getResponse()->getContent());
    }

    public function testHealthWorksWithoutCheckingDatabase(): void
    {
        $client = self::createClient();
        $readiness = $this->createMock(ReadinessCheckInterface::class);
        $readiness->expects(self::never())->method('isReady');
        self::getContainer()->set(ReadinessCheckInterface::class, $readiness);

        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }

    public function testReadinessSuccess(): void
    {
        $client = self::createClient();
        $readiness = $this->createMock(ReadinessCheckInterface::class);
        $readiness->expects(self::once())->method('isReady')->willReturn(true);
        self::getContainer()->set(ReadinessCheckInterface::class, $readiness);

        $client->request('GET', '/api/ready');

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testReadinessFailureReturnsGenericUnavailableResponse(): void
    {
        $client = self::createClient();
        $readiness = $this->createMock(ReadinessCheckInterface::class);
        $readiness->expects(self::once())->method('isReady')->willReturn(false);
        self::getContainer()->set(ReadinessCheckInterface::class, $readiness);

        $client->request('GET', '/api/ready');

        self::assertResponseStatusCodeSame(503);
        self::assertSame([
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Serviço temporariamente indisponível.',
            ],
        ], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testUnsupportedMethodReturnsJsonAndAllowHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/health');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('GET', $client->getResponse()->headers->get('Allow'));
        self::assertSame('METHOD_NOT_ALLOWED', json_decode(
            $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR,
        )['error']['code']);
    }

    public function testUnknownRouteReturnsJsonWithoutInternalDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/not-a-route?token=must-not-appear');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Recurso não encontrado.'],
        ], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
