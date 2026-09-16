<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Administration\UserAccessService;
use App\Auth\SessionService;
use App\Entity\User;
use App\Enum\AuthClientType;
use App\Enum\UserRole;
use App\Security\AuthenticatedActor;
use App\Security\JwtService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?Connection $db = null;
    private array $users = [];
    private array $ministries = [];
    private array $posts = [];
    private array $events = [];
    private array $schedules = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Requires isolated PostgreSQL.');
        }
        $this->client = self::createClient();
        $this->db = DriverManager::getConnection(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams());
        self::assertStringEndsWith('_test', (string) $this->db->fetchOne('SELECT current_database()'));
    }

    protected function tearDown(): void
    {
        if ($this->db?->isTransactionActive()) {
            $this->db->rollBack();
        }
        foreach ($this->posts as $id) {
            $this->db->delete('comments', ['post_id' => $id]);
            $this->db->delete('posts', ['id' => $id]);
        }
        foreach ($this->events as $id) { $this->db->delete('events', ['id' => $id]); }
        foreach ($this->schedules as $id) { $this->db->delete('ministry_schedules', ['id' => $id]); }
        foreach ($this->users as $id) {
            $this->db->executeStatement("DELETE FROM audit_logs WHERE actor_id = ? OR (entity_type = 'users' AND entity_id = ?)", [$id, $id]);
            $this->db->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
            $this->db->delete('auth_sessions', ['user_id' => $id]);
            $this->db->delete('user_ministries', ['user_id' => $id]);
        }
        foreach ($this->users as $id) {
            $this->db->delete('users', ['id' => $id]);
        }
        foreach ($this->ministries as $id) {
            $this->db->delete('ministries', ['id' => $id]);
        }
        $this->db?->close();
        parent::tearDown();
    }

    public function testUsersRequireAdminOrPastorAndPastorScopePrecedesCounting(): void
    {
        $admin = $this->account('ADMIN');
        $pastor = $this->account('PASTOR');
        $member = $this->account('MEMBER');
        $this->request('GET', '/api/admin/users', null);
        self::assertResponseStatusCodeSame(401);
        $this->request('GET', '/api/admin/users', $member);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', '/api/admin/users?limit=1', $pastor);
        self::assertResponseIsSuccessful();
        $body = $this->body();
        self::assertSame(2, $body['pagination']['total']);
        self::assertCount(1, $body['items']);
        self::assertNotSame('ADMIN', $body['items'][0]['role']);
        $this->request('GET', '/api/admin/users?role=ADMIN', $pastor);
        self::assertSame(0, $this->body()['pagination']['total']);
        $this->request('GET', '/api/admin/users/'.$admin['id'], $pastor);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/admin/users', $admin);
        self::assertSame(3, $this->body()['pagination']['total']);
        foreach ($this->body()['items'] as $item) {
            self::assertSame(['id', 'name', 'email', 'role', 'status'], array_keys($item));
        }
    }

    public function testPermissionResponseDistinguishesGlobalAdministrationAndLeadership(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $this->request('GET', '/api/auth/permissions', $admin);
        self::assertSame(['manage_users' => true, 'manage_ministries' => true, 'manage_settings' => true, 'led_ministries' => []], $this->body()['permissions']);
        $this->request('GET', '/api/auth/permissions', $member);
        self::assertFalse($this->body()['permissions']['manage_users']);
        self::assertFalse($this->body()['permissions']['manage_settings']);
    }

    public function testPastorCannotChangeAnAdministratorOrPromoteToAdministrator(): void
    {
        $admin = $this->account('ADMIN');
        $pastor = $this->account('PASTOR');
        $member = $this->account('MEMBER');
        foreach ([[$admin['id'], ['status' => 'ACTIVE']], [$admin['id'], ['status' => 'BLOCKED']], [$member['id'], ['role' => 'ADMIN']]] as [$id, $body]) {
            $this->request('PATCH', '/api/admin/users/'.$id.'/access', $pastor, $body);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs WHERE actor_id = ?', [$pastor['id']]));
    }

    public function testRoleChangeRevokesLeadershipImmediatelyAndAuditContainsOnlyAccessState(): void
    {
        $admin = $this->account('ADMIN');
        $leader = $this->account('LEADER');
        $suffix = bin2hex(random_bytes(8));
        $ministryId = (int) $this->db->fetchOne("INSERT INTO ministries (name, slug, description, status, created_at, updated_at) VALUES ('Test', ?, NULL, 'ACTIVE', date_trunc('second', clock_timestamp()), date_trunc('second', clock_timestamp())) RETURNING id", ['admin-test-'.$suffix]);
        $this->ministries[] = $ministryId;
        $this->db->executeStatement("INSERT INTO user_ministries (user_id, ministry_id, is_leader, status, joined_at, created_at, updated_at) VALUES (?, ?, TRUE, 'ACTIVE', date_trunc('second', clock_timestamp()), date_trunc('second', clock_timestamp()), date_trunc('second', clock_timestamp()))", [$leader['id'], $ministryId]);
        $this->request('PATCH', '/api/admin/users/'.$leader['id'].'/access', $admin, ['role' => 'MEMBER']);
        self::assertResponseIsSuccessful();
        self::assertSame('MEMBER', $this->body()['user']['role']);
        self::assertFalse($this->db->fetchOne('SELECT is_leader FROM user_ministries WHERE user_id = ?', [$leader['id']]));
        $this->request('GET', '/api/auth/me', $leader);
        self::assertSame('MEMBER', $this->body()['user']['role']);
        $audit = json_decode($this->db->fetchOne('SELECT metadata FROM audit_logs WHERE actor_id = ? AND entity_id = ?', [$admin['id'], $leader['id']]), true);
        self::assertEqualsCanonicalizing(['before', 'after', 'leadership_removed_count'], array_keys($audit));
        self::assertSame(['role' => 'LEADER', 'status' => 'ACTIVE'], $audit['before']);
        self::assertSame(1, $audit['leadership_removed_count']);
        $this->request('PATCH', '/api/admin/users/'.$leader['id'].'/access', $admin, ['role' => 'MEMBER']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs WHERE actor_id = ?', [$admin['id']]));
    }

    public function testBlockingInvalidatesSessionAndReactivationDoesNotRestoreIt(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $this->request('PATCH', '/api/admin/users/'.$member['id'].'/access', $admin, ['status' => 'BLOCKED']);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/auth/me', $member);
        self::assertResponseStatusCodeSame(401);
        $this->request('PATCH', '/api/admin/users/'.$member['id'].'/access', $admin, ['status' => 'ACTIVE']);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/auth/me', $member);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLastActiveAdminCannotBeDemotedOrBlocked(): void
    {
        $admin = $this->account('ADMIN');
        foreach ([['role' => 'MEMBER'], ['status' => 'INACTIVE'], ['status' => 'BLOCKED']] as $data) {
            $this->request('PATCH', '/api/admin/users/'.$admin['id'].'/access', $admin, $data);
            self::assertResponseStatusCodeSame(409);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs WHERE actor_id = ?', [$admin['id']]));
        self::assertSame('ADMIN', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$admin['id']]));
    }

    public function testDemotedAdministratorCannotKeepPowerUsingAnOldAccessToken(): void
    {
        $admin = $this->account('ADMIN');
        $other = $this->account('ADMIN');
        $this->request('PATCH', '/api/admin/users/'.$other['id'].'/access', $admin, ['role' => 'MEMBER']);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/admin/users', $other);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', '/api/admin/users/'.$other['id'].'/access', $other, ['role' => 'ADMIN']);
        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('invalidBodies')]
    public function testAccessInputRejectsMassAssignmentAndInvalidTypes(array $body): void
    {
        $admin = $this->account('ADMIN');
        $this->request('PATCH', '/api/admin/users/'.$admin['id'].'/access', $admin, $body);
        self::assertResponseStatusCodeSame(422);
    }

    public static function invalidBodies(): iterable
    {
        yield [[]];
        yield [['role' => 'SUPER_ADMIN']];
        yield [['role' => null]];
        yield [['role' => ['ADMIN']]];
        yield [['status' => 1]];
        yield [['status' => 'active']];
        yield [['role' => 'ADMIN', 'password_hash' => 'injected']];
        yield [['role' => 'ADMIN', 'id' => 123]];
    }

    public function testOnlyAccessRoutePermitsPatchPreflight(): void
    {
        $this->client->request('OPTIONS', 'https://localhost/api/admin/users/1/access', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Methods', 'PATCH');
    }

    public function testAuditFailureRollsBackTheAccessChange(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $this->db->executeStatement('ALTER TABLE audit_logs ADD CONSTRAINT test_reject_audit CHECK (false) NOT VALID');
        try {
            $this->request('PATCH', '/api/admin/users/'.$member['id'].'/access', $admin, ['status' => 'BLOCKED']);
            self::assertResponseStatusCodeSame(500);
            self::assertSame('ACTIVE', $this->db->fetchOne('SELECT status FROM users WHERE id = ?', [$member['id']]));
            self::assertNull($this->db->fetchOne('SELECT revoked_at FROM auth_sessions WHERE id = ?', [$member['session']]));
        } finally {
            $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_audit');
        }
    }

    public function testConcurrentDemotionsPreserveOneAdministrator(): void
    {
        $first = $this->account('ADMIN');
        $second = $this->account('ADMIN');
        $results = $this->concurrent([$first, $second]);
        self::assertEqualsCanonicalizing(['ok', 'conflict'], $results);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM users WHERE role = 'ADMIN' AND status = 'ACTIVE'"));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }

    public function testSessionRevokedWhileWaitingCannotCompleteTheChange(): void
    {
        $first = $this->account('ADMIN');
        $second = $this->account('ADMIN');
        self::assertEqualsCanonicalizing(['ok', 'unauthorized'], $this->concurrent([$first, $second], $second['session']));
        self::assertSame('ADMIN', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$second['id']]));
    }

    public function testConcurrentUserCreationCommitsOneAccountAndOneAudit(): void
    {
        $first = $this->account('ADMIN');
        $second = $this->account('ADMIN');
        $email = 'concurrent-user-'.bin2hex(random_bytes(8)).'@example.test';
        try {
            $results = $this->concurrent([$first, $second], operation: [
                'action' => 'create_user',
                'data' => ['name' => 'Concurrent user', 'email' => $email, 'password' => 'test-only-'.bin2hex(random_bytes(12))],
            ]);
            self::assertEqualsCanonicalizing(['ok', 'conflict'], $results);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM users WHERE email_normalized = ?', [$email]));
            self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'user.created'"));
        } finally {
            foreach ($this->db->fetchFirstColumn('SELECT id FROM users WHERE email_normalized = ?', [$email]) as $id) {
                $this->users[] = (int) $id;
            }
        }
    }

    public function testProfileMutationRechecksSessionAfterWaitingForTheLock(): void
    {
        $first = $this->account('ADMIN');
        $second = $this->account('ADMIN');
        $results = $this->concurrent([$first, $second], $second['session'], [
            'action' => 'profile', 'data' => ['name' => 'Updated profile'],
        ]);
        self::assertEqualsCanonicalizing(['ok', 'unauthorized'], $results);
        self::assertSame('Test', $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [$second['id']]));
        self::assertSame('Updated profile', $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [$first['id']]));
    }

    public function testConcurrentMembershipCreationHasOneRelationshipAndOneAudit(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $id = $this->ministryForConcurrency();
        self::assertSame(['ok', 'ok'], $this->concurrent([$first, $second], operation: [
            'action' => 'ministry_join', 'ministry' => $id, 'target' => $member['id'],
        ]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_ministries WHERE ministry_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'membership.join'"));
    }

    public function testLeadershipGrantRechecksSessionAfterWaitingForAnotherAdministrator(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('ADMIN'); $leader = $this->account('LEADER');
        $id = $this->ministryForConcurrency();
        self::assertEqualsCanonicalizing(['ok', 'unauthorized'], $this->concurrent([$first, $second], $second['session'], [
            'action' => 'ministry_lead', 'ministry' => $id, 'target' => $leader['id'],
        ]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'membership.lead'"));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs WHERE actor_id = ?', [$second['id']]));
        self::assertTrue($this->db->fetchOne('SELECT is_leader FROM user_ministries WHERE ministry_id = ? AND user_id = ?', [$id, $leader['id']]));
    }

    public function testConcurrentPublicationWritesOneStatusAudit(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->postForConcurrency($first['id']);
        self::assertSame(['ok','ok'], $this->concurrent([$first,$second], operation: ['action'=>'post_publish','target'=>$id]));
        self::assertSame('PUBLISHED', $this->db->fetchOne('SELECT status FROM posts WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'posts' AND entity_id = ?", [$id]));
    }

    public function testPublicationRechecksSessionAfterWaitingForLock(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->postForConcurrency($first['id']);
        self::assertEqualsCanonicalizing(['ok','unauthorized'], $this->concurrent([$first,$second], $second['session'], ['action'=>'post_publish','target'=>$id]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE actor_id = ? AND entity_type = 'posts'", [$second['id']]));
    }

    public function testConcurrentCommentCreationRejectsRevokedSession(): void
    {
        $first = $this->account('MEMBER'); $second = $this->account('MEMBER'); $post = $this->postForConcurrency($first['id']);
        $this->db->executeStatement("UPDATE posts SET status = 'PUBLISHED', published_at = date_trunc('second',clock_timestamp()) WHERE id = ?", [$post]);
        self::assertEqualsCanonicalizing(['ok','unauthorized'], $this->concurrent([$first,$second], $second['session'], ['action'=>'comment_create','target'=>$post]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM comments WHERE post_id = ?', [$post]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE actor_id = ? AND entity_type = 'comments'", [$second['id']]));
    }

    public function testClosingCommentsWhileCreationWaitsRejectsBothMessages(): void
    {
        $first = $this->account('MEMBER'); $second = $this->account('MEMBER'); $post = $this->postForConcurrency($first['id']);
        $this->db->executeStatement("UPDATE posts SET status = 'PUBLISHED', published_at = date_trunc('second',clock_timestamp()) WHERE id = ?", [$post]);
        self::assertSame(['conflict','conflict'], $this->concurrent([$first,$second], operation: ['action'=>'comment_create','target'=>$post], whileWaiting: function () use ($post): void {
            $this->db->update('posts', ['comments_enabled' => 'false'], ['id' => $post]);
        }));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM comments WHERE post_id = ?', [$post]));
    }

    public function testConcurrentCommentModerationWritesOneAudit(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $post = $this->postForConcurrency($first['id']);
        $comment = (int) $this->db->fetchOne("INSERT INTO comments (post_id,user_id,content,status,created_at,updated_at) VALUES (?,?,'Concurrent','VISIBLE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id", [$post,$first['id']]);
        self::assertSame(['ok','ok'], $this->concurrent([$first,$second], operation: ['action'=>'comment_moderate','post'=>$post,'target'=>$comment]));
        self::assertSame('HIDDEN', $this->db->fetchOne('SELECT status FROM comments WHERE id = ?', [$comment]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'comments' AND entity_id = ?", [$comment]));
    }

    public function testConcurrentCancellationWritesOneAudit(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->eventForConcurrency($first['id']);
        self::assertSame(['ok','ok'], $this->concurrent([$first,$second], operation: ['action'=>'event_cancel','target'=>$id]));
        self::assertSame('CANCELLED', $this->db->fetchOne('SELECT status FROM events WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'events' AND entity_id = ?", [$id]));
    }

    public function testEventCancellationRechecksSessionAfterWaiting(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->eventForConcurrency($first['id']);
        self::assertEqualsCanonicalizing(['ok','unauthorized'], $this->concurrent([$first,$second], $second['session'], ['action'=>'event_cancel','target'=>$id]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE actor_id = ? AND entity_type = 'events'", [$second['id']]));
    }

    public function testConcurrentReschedulingValidatesLatestEndTime(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->eventForConcurrency($first['id']);
        self::assertSame(['invalid','invalid'], $this->concurrent([$first,$second], operation: [
            'action'=>'event_update','target'=>$id,'data'=>['starts_at'=>'2026-10-04T12:00:00Z'],
        ], whileWaiting: function () use ($id): void {
            $this->db->update('events', ['ends_at'=>'2026-10-04 11:00:00+00:00'], ['id'=>$id]);
        }));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'events' AND entity_id = ?", [$id]));
    }

    public function testConcurrentScheduleCancellationWritesOneAudit(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->scheduleForConcurrency($first['id']);
        self::assertSame(['ok','ok'], $this->concurrent([$first,$second], operation: ['action'=>'schedule_cancel','target'=>$id]));
        self::assertSame('CANCELLED', $this->db->fetchOne('SELECT status FROM ministry_schedules WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'ministry_schedules' AND entity_id = ?", [$id]));
    }

    public function testScheduleCancellationRechecksSessionAfterWaiting(): void
    {
        $first = $this->account('ADMIN'); $second = $this->account('PASTOR'); $id = $this->scheduleForConcurrency($first['id']);
        self::assertEqualsCanonicalizing(['ok','unauthorized'], $this->concurrent([$first,$second], $second['session'], ['action'=>'schedule_cancel','target'=>$id]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE actor_id = ? AND entity_type = 'ministry_schedules'", [$second['id']]));
    }

    private function scheduleForConcurrency(int $author): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO ministry_schedules (created_by,title,starts_at,visibility,status,created_at,updated_at) VALUES (?,'Concurrent','2099-10-04 10:00:00+00','PUBLIC','PUBLISHED',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id", [$author]);
        $this->schedules[] = $id;
        return $id;
    }

    private function eventForConcurrency(int $author): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO events (created_by,title,starts_at,ends_at,visibility,status,created_at,updated_at) VALUES (?,'Concurrent','2026-10-04 10:00:00+00','2026-10-04 14:00:00+00','PUBLIC','PUBLISHED',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id", [$author]);
        $this->events[] = $id;
        return $id;
    }

    private function postForConcurrency(int $author): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO posts (author_id,title,content,visibility,comments_enabled,status,created_at,updated_at) VALUES (?,'Concurrent','Text','PUBLIC',TRUE,'DRAFT',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", [$author]);
        $this->posts[] = $id;
        return $id;
    }

    private function ministryForConcurrency(): int
    {
        $id = (int) $this->db->fetchOne("INSERT INTO ministries (name,slug,status,created_at,updated_at) VALUES ('Concurrent ministry',?,'ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id", ['concurrent-'.bin2hex(random_bytes(8))]);
        $this->ministries[] = $id;
        return $id;
    }

    private function account(string $role): array
    {
        $email = 'admin-http-'.bin2hex(random_bytes(8)).'@example.test';
        $password = 'test-only-'.bin2hex(random_bytes(8));
        $id = (int) $this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Test',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", [$email, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $role]);
        $this->users[] = $id;
        $session = self::getContainer()->get(SessionService::class)->login($email, $password, AuthClientType::MOBILE);

        return ['id' => $id, 'session' => $session->sessionId, 'token' => self::getContainer()->get(JwtService::class)->issue((string) $id, $session->sessionId)];
    }

    private function request(string $method, string $path, ?array $actor, ?array $body = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($actor !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$actor['token'];
        }
        $this->client->request($method, 'https://localhost'.$path, server: $headers, content: $body === null ? null : json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function concurrent(array $actors, ?string $revokeSession = null, array $operation = [], ?callable $whileWaiting = null): array
    {
        $workers = [];
        $this->db->beginTransaction();
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(841920041)');
        try {
            foreach ($actors as $actor) {
                $label = 'admin-access-'.bin2hex(random_bytes(8));
                $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Support/auth-concurrency-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['action' => 'access', 'actor' => (string) $actor['id'], 'session' => $actor['session'], 'target' => (string) $actor['id'], 'role' => 'MEMBER', 'label' => $label, ...$operation], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes, $label];
            }
            foreach ($workers as [, $pipes]) {
                self::assertSame("READY\n", fgets($pipes[1]));
            }
            $deadline = microtime(true) + 10;
            do {
                $this->db->executeQuery('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $this->db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = 'Lock'", [$workers[0][2], $workers[1][2]]);
                if ($waiting === 2) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting);
            if ($whileWaiting !== null) { $whileWaiting(); }
            if ($revokeSession !== null) {
                $this->db->executeStatement("UPDATE auth_sessions SET revoked_at = date_trunc('second', clock_timestamp()) WHERE id = ?", [$revokeSession]);
            }
            $this->db->commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $output);
                self::assertSame('', $error);
                $results[] = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            $workers = [];

            return $results;
        } finally {
            if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (is_resource($process)) { proc_close($process); }
            }
        }
    }
}
