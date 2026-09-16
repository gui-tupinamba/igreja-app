<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthHttpBoundaryTest extends WebTestCase
{
    public function testProtectedEndpointRejectsPlainHttpBeforeReadingBearerOrDatabase(): void
    {
        $client = self::createClient();
        $client->request('GET', 'http://api.church.example/api/auth/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.json.signature',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame('FORBIDDEN', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testHttpsWithoutBearerReturnsGenericUnauthorizedWithoutDatabase(): void
    {
        $client = self::createClient();
        $client->request('GET', 'https://api.church.example/api/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSame([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Autenticação necessária.'],
        ], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
