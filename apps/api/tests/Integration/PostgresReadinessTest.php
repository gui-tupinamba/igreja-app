<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PostgresReadinessTest extends WebTestCase
{
    public function testReadinessWithRealPostgres(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DATABASE_TESTS=1 and DATABASE_URL to test a real PostgreSQL connection.');
        }

        $client = self::createClient();
        $client->request('GET', '/api/ready');

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }
}
