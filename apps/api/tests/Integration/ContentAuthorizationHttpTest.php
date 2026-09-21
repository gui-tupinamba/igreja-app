<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\SessionService;
use App\Enum\AuthClientType;
use App\Security\JwtService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** HTTP authorization against committed, owned fixtures in the isolated PostgreSQL database. */
final class ContentAuthorizationHttpTest extends WebTestCase
{
    private const PASSWORD = 'Content-fixture-password-2026!';

    private ?Connection $connection = null;
    private KernelBrowser $client;
    /** @var array<string, list<int>> */
    private array $ids = ['comments' => [], 'events' => [], 'posts' => [], 'user_ministries' => [], 'ministries' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires the isolated migrated PostgreSQL test database.');
        }
        $this->client = self::createClient();
        $managed = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection = DriverManager::getConnection($managed->getParams());
        self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());
        if (!str_ends_with((string) $this->connection->fetchOne('SELECT current_database()'), '_test')) {
            self::fail('Refusing content fixtures outside an actual PostgreSQL database ending in _test.');
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null) {
                foreach ($this->ids as $table => $ids) {
                    foreach ($ids as $id) {
                        if ($table === 'users') {
                            $this->connection->executeStatement('UPDATE refresh_tokens SET replaced_by_id = NULL WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
                            $this->connection->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
                            $this->connection->executeStatement('DELETE FROM auth_sessions WHERE user_id = ?', [$id]);
                        }
                        $this->connection->executeStatement("DELETE FROM $table WHERE id = ?", [$id]);
                    }
                }
                $this->connection->close();
            }
        } finally {
            $this->connection = null;
            parent::tearDown();
        }
    }

    #[DataProvider('roles')]
    public function testPublishedReadMatrixAndPaginationNeverRevealForeignPrivateContent(string $role, bool $global): void
    {
        $g = $this->graph($role);
        $token = $this->login($g['actor']);
        foreach (['general', 'public_other', 'private_own'] as $key) {
            $this->get('/api/posts/'.$g[$key], $token);
            self::assertResponseStatusCodeSame(200);
            self::assertSame($g[$key], $this->json()['post']['id']);
            self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
            self::assertEqualsCanonicalizing(['id', 'title', 'content', 'visibility', 'status', 'ministry_id', 'published_at', 'author_id', 'comments_enabled', 'images', 'created_at', 'updated_at'], array_keys($this->json()['post']));
        }
        $this->get('/api/posts/'.$g['private_other'], $token);
        self::assertResponseStatusCodeSame($global ? 200 : 404);
        if (!$global) {
            self::assertSame('NOT_FOUND', $this->json()['error']['code']);
        }

        $expected = $global ? [$g['private_other'], $g['private_own'], $g['public_other'], $g['general']]
            : [$g['private_own'], $g['public_other'], $g['general']];
        foreach ($expected as $index => $id) {
            $this->get('/api/posts?page='.($index + 1).'&limit=1', $token);
            self::assertResponseStatusCodeSame(200);
            $response = $this->json();
            self::assertSame(['page' => $index + 1, 'limit' => 1, 'total' => count($expected)], $response['pagination']);
            self::assertSame([$id], array_column($response['items'], 'id'));
            self::assertStringNotContainsString('password_hash', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('@example.test', (string) $this->client->getResponse()->getContent());
        }
        $this->get('/api/posts?page=2147483647&limit=100', $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['items']);
        self::assertSame(count($expected), $this->json()['pagination']['total']);
    }

    public static function roles(): iterable
    {
        yield 'member' => ['MEMBER', false];
        yield 'leader' => ['LEADER', false];
        yield 'pastor' => ['PASTOR', true];
        yield 'admin' => ['ADMIN', true];
    }

    public function testRegularReadExcludesDraftFutureArchiveAndInactiveMinistryEvenForAdmin(): void
    {
        $g = $this->graph('ADMIN');
        $token = $this->login($g['actor']);
        $draft = $this->post($g['actor']['id'], $g['own'], 'PUBLIC', 'DRAFT');
        $future = $this->post($g['actor']['id'], $g['own'], 'PUBLIC', published: '2099-01-01 00:00:00+00');
        $archived = $this->post($g['actor']['id'], $g['own'], 'PUBLIC', 'ARCHIVED');
        $inactive = $this->ministry('INACTIVE');
        $inactivePost = $this->post($g['actor']['id'], $inactive, 'PUBLIC');
        foreach ([$draft, $future, $archived, $inactivePost] as $id) {
            $this->get('/api/posts/'.$id, $token);
            self::assertResponseStatusCodeSame(404);
        }
        $this->get('/api/posts', $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['pagination']['total']);
        self::assertCount(4, $this->json()['items']);
    }

    public function testNestedIdsAndCommentStateCannotBypassParentReadAuthorization(): void
    {
        $g = $this->graph('MEMBER');
        $token = $this->login($g['actor']);
        $event = $this->event($g['actor']['id'], $g['other'], 'PUBLIC');
        $privateEvent = $this->event($g['actor']['id'], $g['other'], 'MINISTRY_MEMBERS');
        $ownEvent = $this->event($g['actor']['id'], $g['own'], 'MINISTRY_MEMBERS');
        $this->get('/api/ministries/'.$g['other'].'/events/'.$event, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($event, $this->json()['event']['id']);
        self::assertSame('2026-10-01T12:00:00Z', $this->json()['event']['starts_at']);
        $this->get('/api/ministries/'.$g['own'].'/events/'.$event, $token);
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/ministries/'.$g['other'].'/events/'.$privateEvent, $token);
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/ministries/'.$g['own'].'/events/'.$ownEvent, $token);
        self::assertResponseStatusCodeSame(200);
        foreach (['CANCELLED' => 200, 'DRAFT' => 404, 'ARCHIVED' => 404] as $status => $expected) {
            $this->connection->executeStatement('UPDATE events SET status = ? WHERE id = ?', [$status, $event]);
            $this->get('/api/ministries/'.$g['other'].'/events/'.$event, $token);
            self::assertResponseStatusCodeSame($expected);
        }

        $comment = $this->comment($g['public_other'], $g['actor']['id']);
        $privateComment = $this->comment($g['private_other'], $g['actor']['id']);
        $this->get('/api/posts/'.$g['public_other'].'/comments/'.$comment, $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($comment, $this->json()['comment']['id']);
        $this->get('/api/posts/'.$g['general'].'/comments/'.$comment, $token);
        self::assertResponseStatusCodeSame(404);
        // Being the comment's author does not permit reading its private parent.
        $this->get('/api/posts/'.$g['private_other'].'/comments/'.$privateComment, $token);
        self::assertResponseStatusCodeSame(404);
        foreach (['HIDDEN', 'DELETED'] as $status) {
            $this->connection->executeStatement('UPDATE comments SET status = ? WHERE id = ?', [$status, $comment]);
            $this->get('/api/posts/'.$g['public_other'].'/comments/'.$comment, $token);
            self::assertResponseStatusCodeSame(404);
        }
        $this->connection->executeStatement("UPDATE comments SET status = 'VISIBLE' WHERE id = ?", [$comment]);
        $this->connection->executeStatement("UPDATE posts SET status = 'DRAFT' WHERE id = ?", [$g['public_other']]);
        $this->get('/api/posts/'.$g['public_other'].'/comments/'.$comment, $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMembershipRoleMinistryAndSessionRevocationApplyToExistingAccessToken(): void
    {
        $g = $this->graph('LEADER');
        $token = $this->login($g['actor']);
        $ownPath = '/api/posts/'.$g['private_own'];
        $this->connection->executeStatement('UPDATE user_ministries SET is_leader = TRUE WHERE id = ?', [$g['membership']]);
        $this->get($ownPath, $token);
        self::assertResponseStatusCodeSame(200);
        $this->connection->executeStatement('UPDATE user_ministries SET is_leader = FALSE WHERE id = ?', [$g['membership']]);
        $this->get($ownPath, $token);
        self::assertResponseStatusCodeSame(200); // Participation, not leadership, grants this read.
        $this->connection->executeStatement("UPDATE user_ministries SET status = 'INACTIVE', left_at = CURRENT_TIMESTAMP WHERE id = ?", [$g['membership']]);
        $this->get($ownPath, $token);
        self::assertResponseStatusCodeSame(404);
        $this->get('/api/posts', $token);
        self::assertSame(2, $this->json()['pagination']['total']);
        $this->connection->executeStatement("UPDATE user_ministries SET status = 'ACTIVE', left_at = NULL WHERE id = ?", [$g['membership']]);
        $this->get($ownPath, $token);
        self::assertResponseStatusCodeSame(200);
        $this->connection->executeStatement("UPDATE ministries SET status = 'INACTIVE' WHERE id = ?", [$g['own']]);
        $this->get($ownPath, $token);
        self::assertResponseStatusCodeSame(404);
        $this->connection->executeStatement("UPDATE users SET role = 'PASTOR' WHERE id = ?", [$g['actor']['id']]);
        $this->get('/api/posts/'.$g['private_other'], $token);
        self::assertResponseStatusCodeSame(200);
        $this->connection->executeStatement("UPDATE users SET role = 'MEMBER' WHERE id = ?", [$g['actor']['id']]);
        $this->get('/api/posts/'.$g['private_other'], $token);
        self::assertResponseStatusCodeSame(404);
        $this->connection->executeStatement('UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ?', [$g['actor']['id']]);
        $this->get('/api/posts/'.$g['public_other'], $token);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousRequestsAndMalformedParametersAreRejected(): void
    {
        $g = $this->graph('MEMBER');
        foreach (['/api/posts', '/api/posts/'.$g['general'], '/api/ministries/1/events/1', '/api/posts/1/comments/1'] as $path) {
            $this->get($path);
            self::assertResponseStatusCodeSame(401);
        }
        $token = $this->login($g['actor']);
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=2147483648', 'page=1e2', 'page[]=1', 'limit=0', 'limit=101', 'limit[]=2', 'visibility=INVALID', 'status=DRAFT'] as $query) {
            $this->get('/api/posts?'.$query, $token);
            self::assertResponseStatusCodeSame(422);
        }
        foreach (['0', '01', '2147483648', '999999999999999999999', '-1'] as $id) {
            $this->get('/api/posts/'.$id, $token);
            self::assertResponseStatusCodeSame(404);
        }
        $this->get('/api/posts/'.$g['general'].'?actor_id='.$g['actor']['id'], $token);
        self::assertResponseStatusCodeSame(422);
        $this->connection->executeStatement("UPDATE users SET status = 'INACTIVE' WHERE id = ?", [$g['actor']['id']]);
        $this->get('/api/posts', $token);
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function graph(string $role): array
    {
        $actor = $this->user($role);
        $own = $this->ministry();
        $other = $this->ministry();
        $membership = $this->insert('user_ministries', ['user_id' => $actor['id'], 'ministry_id' => $own, 'status' => 'ACTIVE', 'is_leader' => 0, 'joined_at' => '2026-01-01 00:00:00+00', 'left_at' => null]);

        return [
            'actor' => $actor, 'own' => $own, 'other' => $other, 'membership' => $membership,
            'general' => $this->post($actor['id'], null, 'PUBLIC'),
            'public_other' => $this->post($actor['id'], $other, 'PUBLIC'),
            'private_own' => $this->post($actor['id'], $own, 'MINISTRY_MEMBERS'),
            'private_other' => $this->post($actor['id'], $other, 'MINISTRY_MEMBERS'),
        ];
    }

    /** @return array{id: int, email: string} */
    private function user(string $role): array
    {
        static $hash = null;
        $hash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $email = 'content-'.bin2hex(random_bytes(10)).'@example.test';

        return ['id' => $this->insert('users', ['name' => 'Content fixture', 'email' => $email, 'email_normalized' => $email, 'password_hash' => $hash, 'role' => $role, 'status' => 'ACTIVE']), 'email' => $email];
    }

    private function ministry(string $status = 'ACTIVE'): int
    {
        return $this->insert('ministries', ['name' => 'Content ministry', 'slug' => 'content-'.bin2hex(random_bytes(10)), 'status' => $status]);
    }

    private function post(int $author, ?int $ministry, string $visibility, string $status = 'PUBLISHED', string $published = '2026-01-01 00:00:00+00'): int
    {
        return $this->insert('posts', ['author_id' => $author, 'ministry_id' => $ministry, 'title' => 'Scoped post', 'content' => 'Scoped content', 'visibility' => $visibility, 'status' => $status, 'comments_enabled' => 1, 'published_at' => $published]);
    }

    private function event(int $author, int $ministry, string $visibility): int
    {
        return $this->insert('events', ['created_by' => $author, 'ministry_id' => $ministry, 'title' => 'Scoped event', 'starts_at' => '2026-10-01 12:00:00+00', 'visibility' => $visibility, 'status' => 'PUBLISHED']);
    }

    private function comment(int $post, int $user): int
    {
        return $this->insert('comments', ['post_id' => $post, 'user_id' => $user, 'content' => 'Scoped comment', 'status' => 'VISIBLE']);
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data): int
    {
        $data += ['created_at' => '2026-01-01 00:00:00+00', 'updated_at' => '2026-01-01 00:00:00+00'];
        $columns = implode(', ', array_keys($data));
        $values = implode(', ', array_fill(0, count($data), '?'));
        $id = (int) $this->connection->fetchOne("INSERT INTO $table ($columns) VALUES ($values) RETURNING id", array_values($data));
        $this->ids[$table][] = $id;

        return $id;
    }

    /** @param array{id: int, email: string} $user */
    private function login(array $user): string
    {
        $result = self::getContainer()->get(SessionService::class)->login($user['email'], self::PASSWORD, AuthClientType::MOBILE);

        return self::getContainer()->get(JwtService::class)->issue((string) $user['id'], $result->sessionId);
    }

    private function get(string $path, ?string $token = null): void
    {
        $this->client->getCookieJar()->clear();
        $headers = ['HTTP_HOST' => 'localhost', 'HTTPS' => 'on'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request('GET', 'https://localhost'.$path, server: $headers);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
