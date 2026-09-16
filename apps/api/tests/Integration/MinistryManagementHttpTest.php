<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\SessionService;
use App\Enum\AuthClientType;
use App\Security\JwtService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MinistryManagementHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?Connection $db = null;
    private array $users = [];
    private array $ministries = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_DATABASE_TESTS') !== '1') { self::markTestSkipped('Requires isolated PostgreSQL.'); }
        $this->client = self::createClient();
        $this->db = DriverManager::getConnection(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams());
        self::assertStringEndsWith('_test', (string) $this->db->fetchOne('SELECT current_database()'));
    }

    protected function tearDown(): void
    {
        if ($this->db?->isTransactionActive()) { $this->db->rollBack(); }
        foreach ($this->users as $id) {
            $this->db->executeStatement('DELETE FROM audit_logs WHERE actor_id = ?', [$id]);
            $this->db->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
            $this->db->delete('auth_sessions', ['user_id' => $id]);
        }
        foreach ($this->ministries as $id) {
            $this->db->delete('posts', ['ministry_id' => $id]);
            $this->db->delete('user_ministries', ['ministry_id' => $id]);
            $this->db->delete('ministries', ['id' => $id]);
        }
        foreach ($this->users as $id) { $this->db->delete('users', ['id' => $id]); }
        $this->db?->close();
        parent::tearDown();
    }

    public function testCreateReadUpdateAndSoftDeletionRespectDirectoryVisibility(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $id = $this->ministry($admin);
        $this->request('GET', '/api/ministries?limit=1', $member);
        self::assertSame(1, $this->body()['pagination']['total']);
        self::assertSame($id, $this->body()['items'][0]['id']);
        self::assertEqualsCanonicalizing(['id','name','slug','description','status','created_at','updated_at'], array_keys($this->body()['items'][0]));
        $this->request('DELETE', '/api/ministries/'.$id, $admin);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/ministries/'.$id, $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/ministries', $admin);
        self::assertSame(0, $this->body()['pagination']['total']);
        $this->request('GET', '/api/admin/ministries?status=INACTIVE', $admin);
        self::assertSame(1, $this->body()['pagination']['total']);
        $this->request('GET', '/api/admin/ministries/'.$id, $member);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', '/api/ministries/'.$id, $admin, ['status' => 'ACTIVE', 'description' => "Descrição\nSegunda linha"]);
        self::assertResponseIsSuccessful();
        $this->request('GET', '/api/ministries/'.$id, $member);
        self::assertSame('ACTIVE', $this->body()['ministry']['status']);
    }

    public function testLeaderEditsOnlyDescriptionAndNameInMinistryActuallyLed(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $member = $this->account('MEMBER');
        $first = $this->ministry($admin); $second = $this->ministry($admin);
        $this->request('POST', "/api/ministries/$first/leaders", $admin, ['user_id' => $leader['id']]);
        self::assertResponseIsSuccessful();
        $this->request('POST', "/api/ministries/$second/members", $admin, ['user_id' => $leader['id']]);
        $this->request('PATCH', "/api/ministries/$first", $leader, ['name' => 'Changed by leader', 'description' => null]);
        self::assertResponseIsSuccessful();
        foreach ([[$second, $leader, ['name' => 'Forbidden']], [$first, $leader, ['status' => 'ACTIVE']], [$first, $leader, ['slug' => 'forbidden']], [$first, $member, ['name' => 'Forbidden']]] as [$id, $actor, $data]) {
            $this->request('PATCH', '/api/ministries/'.$id, $actor, $data);
            self::assertResponseStatusCodeSame(403);
        }
        $this->request('POST', "/api/ministries/$first/members", $leader, ['user_id' => $member['id']]);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', "/api/ministries/$first", $leader);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', "/api/ministries/$first/leaders/".$leader['id'], $admin);
        self::assertResponseStatusCodeSame(204);
        $this->request('PATCH', "/api/ministries/$first", $leader, ['name' => 'Revoked leader']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('LEADER', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$leader['id']]));
    }

    public function testLeadershipPromotionIsExplicitAtomicAndCanSpanSeveralMinistries(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $other = $this->account('LEADER');
        $first = $this->ministry($admin); $second = $this->ministry($admin);
        $this->request('POST', "/api/ministries/$first/leaders", $admin, ['user_id' => $member['id']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('MEMBER', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$member['id']]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM user_ministries WHERE user_id = ?', [$member['id']]));
        $this->request('POST', "/api/ministries/$first/leaders", $admin, ['user_id' => $member['id'], 'promote_to_leader' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->body()['membership']['is_leader']);
        $this->request('POST', "/api/ministries/$second/leaders", $admin, ['user_id' => $member['id']]);
        self::assertResponseIsSuccessful();
        $this->request('POST', "/api/ministries/$first/leaders", $admin, ['user_id' => $other['id']]);
        $this->request('GET', "/api/ministries/$first/leaders?limit=1&page=2", $member);
        self::assertSame(2, $this->body()['pagination']['total']);
        self::assertCount(1, $this->body()['items']);
        $this->request('GET', '/api/profile', $member);
        self::assertCount(2, $this->body()['led_ministries']);
        self::assertSame('LEADER', $this->body()['user']['role']);
    }

    public function testRemovalRevokesPrivateContentAndRejoiningDoesNotRestoreLeadership(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('LEADER');
        $id = $this->ministry($admin);
        $this->request('POST', "/api/ministries/$id/leaders", $admin, ['user_id' => $member['id']]);
        $membershipId = $this->body()['membership']['id'];
        $postId = (int) $this->db->fetchOne("INSERT INTO posts (author_id,ministry_id,title,content,visibility,comments_enabled,status,published_at,created_at,updated_at) VALUES (?,?,'Private','Private','MINISTRY_MEMBERS',TRUE,'PUBLISHED',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", [$admin['id'], $id]);
        $this->request('GET', '/api/posts/'.$postId, $member);
        self::assertResponseIsSuccessful();
        $this->request('DELETE', "/api/ministries/$id/members/".$member['id'], $admin);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/posts/'.$postId, $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', "/api/ministries/$id/members", $admin, ['user_id' => $member['id']]);
        self::assertSame($membershipId, $this->body()['membership']['id']);
        self::assertFalse($this->body()['membership']['is_leader']);
        self::assertNull($this->body()['membership']['left_at']);
        $this->request('GET', '/api/posts/'.$postId, $member);
        self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/ministries/$id", $member, ['name' => 'Cannot lead']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPastorCannotAlterAdministratorMembershipOrLeadership(): void
    {
        $admin = $this->account('ADMIN'); $pastor = $this->account('PASTOR'); $member = $this->account('MEMBER');
        $id = $this->ministry($pastor);
        foreach (['members', 'leaders'] as $type) {
            $this->request('POST', "/api/ministries/$id/$type", $pastor, ['user_id' => $admin['id']]);
            self::assertResponseStatusCodeSame(403);
            $this->request('DELETE', "/api/ministries/$id/$type/".$admin['id'], $pastor);
            self::assertResponseStatusCodeSame(403);
        }
        $this->request('POST', "/api/ministries/$id/leaders", $pastor, ['user_id' => $member['id'], 'promote_to_leader' => true]);
        self::assertResponseIsSuccessful();
        $this->request('POST', "/api/ministries/$id/leaders", $admin, ['user_id' => $admin['id']]);
        self::assertResponseIsSuccessful();
        self::assertSame('ADMIN', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$admin['id']]));
    }

    public function testMemberDirectoryFiltersBeforeCountingAndNeverExposesContacts(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $member = $this->account('MEMBER');
        $id = $this->ministry($admin); $other = $this->ministry($admin);
        foreach ([$leader, $member] as $account) {
            $this->request('POST', "/api/ministries/$id/members", $admin, ['user_id' => $account['id']]);
        }
        $this->request('POST', "/api/ministries/$id/leaders", $admin, ['user_id' => $leader['id']]);
        $this->request('GET', "/api/ministries/$id/members", $member);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', "/api/ministries/$other/members", $leader);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', "/api/ministries/$id/members?limit=1", $leader);
        self::assertSame(2, $this->body()['pagination']['total']);
        self::assertCount(1, $this->body()['items']);
        foreach (['email','phone','birth_date','password_hash'] as $field) { self::assertArrayNotHasKey($field, $this->body()['items'][0]); }
        $this->request('DELETE', "/api/ministries/$id/members/".$member['id'], $admin);
        $this->request('GET', "/api/ministries/$id/members?limit=1&page=2", $leader);
        self::assertSame(1, $this->body()['pagination']['total']);
        self::assertSame([], $this->body()['items']);
        $this->request('GET', "/api/ministries/$id/members?status=INACTIVE", $leader);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', "/api/ministries/$id/members?status=INACTIVE", $admin);
        self::assertSame($member['id'], $this->body()['items'][0]['user_id']);
        // A route to another ministry cannot remove this ministry's membership.
        $this->request('DELETE', "/api/ministries/$other/members/".$leader['id'], $admin);
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->db->fetchOne('SELECT is_leader FROM user_ministries WHERE user_id = ? AND ministry_id = ?', [$leader['id'], $id]));
    }

    public function testInactiveMinistriesAndUsersCannotReceiveMembershipOrLeadership(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $member = $this->account('MEMBER');
        $id = $this->ministry($admin);
        $this->request('POST', "/api/ministries/$id/leaders", $admin, ['user_id' => $leader['id']]);
        $this->request('PATCH', "/api/ministries/$id", $admin, ['status' => 'INACTIVE']);
        $this->request('GET', '/api/profile', $leader);
        self::assertSame([], $this->body()['led_ministries']);
        $this->request('PATCH', "/api/ministries/$id", $leader, ['name' => 'Inactive']);
        self::assertResponseStatusCodeSame(403);
        foreach (['members','leaders'] as $type) {
            $this->request('POST', "/api/ministries/$id/$type", $admin, ['user_id' => $member['id']]);
            self::assertResponseStatusCodeSame(409);
        }
        $this->request('PATCH', "/api/ministries/$id", $admin, ['status' => 'ACTIVE']);
        $this->request('PATCH', '/api/admin/users/'.$member['id'].'/access', $admin, ['status' => 'BLOCKED']);
        $this->request('POST', "/api/ministries/$id/members", $admin, ['user_id' => $member['id']]);
        self::assertResponseStatusCodeSame(409);
        $this->request('PATCH', '/api/admin/users/'.$leader['id'].'/access', $admin, ['role' => 'MEMBER']);
        $this->request('GET', "/api/ministries/$id/members", $leader);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDuplicateSlugAndRepeatedMembershipDoNotDuplicateDataOrAudit(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $id = $this->ministry($admin);
        $slug = $this->db->fetchOne('SELECT slug FROM ministries WHERE id = ?', [$id]);
        $this->request('POST', '/api/ministries', $admin, ['name' => 'Duplicate', 'slug' => $slug]);
        self::assertResponseStatusCodeSame(409);
        for ($i = 0; $i < 2; ++$i) {
            $this->request('POST', "/api/ministries/$id/members", $admin, ['user_id' => $member['id']]);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_ministries WHERE ministry_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'membership.join' AND actor_id = ?", [$admin['id']]));
        $before = (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs');
        $this->request('PATCH', "/api/ministries/$id", $admin, ['slug' => $slug]);
        self::assertResponseIsSuccessful();
        self::assertSame($before, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }

    public function testAuditFailureRollsBackPromotionAndMembershipTogether(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $id = $this->ministry($admin);
        $this->db->executeStatement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_membership CHECK (action <> 'membership.lead') NOT VALID");
        try {
            $this->request('POST', "/api/ministries/$id/leaders", $admin, ['user_id' => $member['id'], 'promote_to_leader' => true]);
            self::assertResponseStatusCodeSame(500);
            self::assertSame('MEMBER', $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$member['id']]));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM user_ministries WHERE user_id = ?', [$member['id']]));
            self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'users' AND entity_id = ?", [$member['id']]));
        } finally {
            $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_membership');
        }
    }

    #[DataProvider('invalidMinistries')]
    public function testInvalidMinistryDataHasNoSideEffects(array $body): void
    {
        $admin = $this->account('ADMIN');
        $this->request('POST', '/api/ministries', $admin, $body);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ministries'));
    }

    public static function invalidMinistries(): iterable
    {
        yield [['name' => 'Name', 'slug' => 'Invalid Slug']];
        yield [['name' => null, 'slug' => 'valid']];
        yield [['name' => 'Name', 'slug' => 'valid', 'status' => 'BLOCKED']];
        yield [['name' => 'Name', 'slug' => 'valid', 'description' => ['private' => 'value']]];
        yield [['name' => 'Name', 'slug' => 'valid', 'is_leader' => true]];
    }

    public function testAuthenticationInputBoundariesAndCors(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $id = $this->ministry($admin);
        foreach (['GET','POST'] as $method) {
            $this->request($method, '/api/ministries', null);
            self::assertResponseStatusCodeSame(401);
        }
        $this->request('POST', '/api/ministries', $member, ['name' => 'No', 'slug' => 'no']);
        self::assertResponseStatusCodeSame(403);
        foreach ([['user_id' => '12'], ['user_id' => $member['id'], 'promote_to_leader' => 'true'], ['user_id' => $member['id'], 'role' => 'ADMIN']] as $body) {
            $this->request('POST', "/api/ministries/$id/leaders", $admin, $body);
            self::assertResponseStatusCodeSame(422);
        }
        $this->request('GET', '/api/ministries?limit=101', $member);
        self::assertResponseStatusCodeSame(422);
        $this->request('GET', '/api/ministries/2147483648', $member);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('OPTIONS', "https://localhost/api/ministries/$id/members/1", server: ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('OPTIONS', 'https://localhost/api/auth/login', server: ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE']);
        self::assertResponseStatusCodeSame(403);
    }

    private function account(string $role): array
    {
        $email = 'ministry-test-'.bin2hex(random_bytes(8)).'@example.test'; $password = 'test-only-'.bin2hex(random_bytes(8));
        $id = (int) $this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Fixture',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id", [$email,$email,password_hash($password,PASSWORD_BCRYPT,['cost'=>4]),$role]);
        $this->users[] = $id;
        $session = self::getContainer()->get(SessionService::class)->login($email,$password,AuthClientType::MOBILE);
        return ['id'=>$id,'session'=>$session->sessionId,'token'=>self::getContainer()->get(JwtService::class)->issue((string)$id,$session->sessionId)];
    }

    private function ministry(array $actor): int
    {
        $slug = 'ministry-test-'.bin2hex(random_bytes(8));
        $this->request('POST','/api/ministries',$actor,['name'=>'Fixture ministry','slug'=>$slug]);
        // Track any committed row even if a later response assertion fails.
        $id = $this->db->fetchOne('SELECT id FROM ministries WHERE slug = ?',[$slug]);
        if ($id !== false) { $this->ministries[] = (int)$id; }
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location','/api/ministries/'.$id);
        return (int)$id;
    }

    private function request(string $method,string $path,?array $actor,?array $body=null): void
    {
        $headers=['CONTENT_TYPE'=>'application/json'];
        if ($actor!==null) { $headers['HTTP_AUTHORIZATION']='Bearer '.$actor['token']; }
        $this->client->request($method,'https://localhost'.$path,server:$headers,content:$body===null?null:json_encode((object)$body,JSON_THROW_ON_ERROR));
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);
    }
}
