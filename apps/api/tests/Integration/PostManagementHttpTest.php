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

final class PostManagementHttpTest extends WebTestCase
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
            $this->db->delete('audit_logs', ['actor_id' => $id]);
            $this->db->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = ?)', [$id]);
            $this->db->delete('auth_sessions', ['user_id' => $id]);
            $this->db->delete('posts', ['author_id' => $id]);
            $this->db->delete('user_ministries', ['user_id' => $id]);
        }
        foreach ($this->ministries as $id) { $this->db->delete('ministries', ['id' => $id]); }
        foreach ($this->users as $id) { $this->db->delete('users', ['id' => $id]); }
        $this->db?->close();
        parent::tearDown();
    }

    public function testDraftPublicationUnpublicationAndArchiveKeepHistory(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $id = $this->post($admin, ['comments_enabled' => false]);
        self::assertSame('DRAFT', $this->body()['post']['status']);
        self::assertNull($this->body()['post']['published_at']);
        self::assertSame($admin['id'], $this->body()['post']['author_id']);
        $this->request('GET', "/api/posts/$id", $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', "/api/admin/posts/$id", $admin);
        self::assertSame('DRAFT', $this->body()['post']['status']);
        $this->request('POST', "/api/posts/$id/publish", $admin, []);
        self::assertResponseIsSuccessful();
        $publishedAt = $this->body()['post']['published_at'];
        self::assertNotNull($publishedAt);
        $this->request('GET', "/api/posts/$id", $member);
        self::assertFalse($this->body()['post']['comments_enabled']);
        $this->request('POST', "/api/posts/$id/unpublish", $admin, []);
        self::assertSame('DRAFT', $this->body()['post']['status']);
        $this->request('GET', '/api/posts', $member);
        self::assertSame(0, $this->body()['pagination']['total']);
        $this->request('DELETE', "/api/posts/$id", $admin);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/admin/posts?status=ARCHIVED', $admin);
        self::assertSame($id, $this->body()['items'][0]['id']);
        $this->request('POST', "/api/posts/$id/publish", $admin, []);
        self::assertSame($publishedAt, $this->body()['post']['published_at']);
        $count = (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs');
        $this->request('POST', "/api/posts/$id/publish", $admin, []);
        self::assertResponseIsSuccessful();
        self::assertSame($count, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }

    public function testFeedSearchAndFiltersDoNotRevealOtherMinistriesPrivatePosts(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $first = $this->ministry(); $second = $this->ministry(); $this->join($member, $first);
        $general = $this->post($admin, ['title' => 'Common general'], true);
        $public = $this->post($admin, ['ministry_id' => $second, 'title' => 'Common public'], true);
        $own = $this->post($admin, ['ministry_id' => $first, 'title' => 'Common internal', 'visibility' => 'MINISTRY_MEMBERS'], true);
        $secret = $this->post($admin, ['ministry_id' => $second, 'title' => 'Other ministry confidential', 'visibility' => 'MINISTRY_MEMBERS'], true);
        $future = $this->post($admin, ['title' => 'Future'], true);
        $this->db->executeStatement("UPDATE posts SET published_at = date_trunc('second',clock_timestamp()) + interval '1 day' WHERE id = ?", [$future]);
        $this->request('GET', '/api/posts?q=Common&limit=1&page=2', $member);
        self::assertSame(3, $this->body()['pagination']['total']);
        self::assertCount(1, $this->body()['items']);
        self::assertContains($this->body()['items'][0]['id'], [$general, $public, $own]);
        foreach (["/api/posts?q=confidential", "/api/posts?ministry_id=$second&visibility=MINISTRY_MEMBERS", '/api/posts?q=%25', '/api/posts?q=%27%20OR%201%3D1--'] as $path) {
            $this->request('GET', $path, $member);
            self::assertSame(0, $this->body()['pagination']['total']);
            self::assertSame([], $this->body()['items']);
        }
        $this->request('GET', '/api/posts?ministry_id=null', $member);
        self::assertSame([$general], array_column($this->body()['items'], 'id'));
        $this->request('GET', '/api/posts?visibility=MINISTRY_MEMBERS', $member);
        self::assertSame([$own], array_column($this->body()['items'], 'id'));
        $this->request('GET', "/api/posts/$secret", $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/posts?q=confidential', $admin);
        self::assertSame(1, $this->body()['pagination']['total']);
    }

    public function testLeadershipScopeAppliesToCreationAdminSearchAndMutations(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER');
        $first = $this->ministry(); $second = $this->ministry();
        $this->join($leader, $first, true); $this->join($leader, $second);
        $own = $this->post($leader, ['ministry_id' => $first]);
        $foreign = $this->post($admin, ['ministry_id' => $second]);
        $general = $this->post($admin);
        $this->request('GET', '/api/admin/posts?status=DRAFT&limit=1', $leader);
        self::assertSame(1, $this->body()['pagination']['total']);
        self::assertSame($own, $this->body()['items'][0]['id']);
        foreach ([$foreign, $general] as $id) {
            $this->request('GET', "/api/admin/posts/$id", $leader);
            self::assertResponseStatusCodeSame(404);
            $this->request('PATCH', "/api/posts/$id", $leader, ['title' => 'Forbidden']);
            self::assertResponseStatusCodeSame(404);
            $this->request('POST', "/api/posts/$id/publish", $leader, []);
            self::assertResponseStatusCodeSame(404);
            $this->request('DELETE', "/api/posts/$id", $leader);
            self::assertResponseStatusCodeSame(404);
        }
        foreach ([null, $second] as $ministry) {
            $this->request('POST', '/api/posts', $leader, ['title' => 'Forbidden', 'content' => 'Text', 'ministry_id' => $ministry]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testMovingPostRequiresBothScopesAndAuthorDoesNotRetainAuthority(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER');
        $first = $this->ministry(); $second = $this->ministry(); $third = $this->ministry();
        $this->join($leader, $first, true); $this->join($leader, $second, true);
        $id = $this->post($leader, ['ministry_id' => $first, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('PATCH', "/api/posts/$id", $leader, ['ministry_id' => $third]);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', "/api/posts/$id", $leader, ['ministry_id' => null, 'visibility' => 'PUBLIC']);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', "/api/posts/$id", $leader, ['ministry_id' => $second]);
        self::assertSame($second, $this->body()['post']['ministry_id']);
        $this->request('PATCH', "/api/posts/$id", $admin, ['ministry_id' => $third]);
        self::assertSame($leader['id'], $this->body()['post']['author_id']);
        $this->request('PATCH', "/api/posts/$id", $leader, ['ministry_id' => $first]);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', "/api/posts/$id", $leader);
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangingVisibilityOrRemovingMinistryValidatesMergedAudience(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $ministry = $this->ministry();
        $id = $this->post($admin, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('GET', "/api/posts/$id", $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', "/api/posts/$id", $admin, ['ministry_id' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($ministry, (int) $this->db->fetchOne('SELECT ministry_id FROM posts WHERE id = ?', [$id]));
        $this->request('PATCH', "/api/posts/$id", $admin, ['ministry_id' => null, 'visibility' => 'PUBLIC']);
        self::assertResponseIsSuccessful();
        $this->request('GET', "/api/posts/$id", $member);
        self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/posts/$id", $admin, ['visibility' => 'MINISTRY_MEMBERS']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMembershipRevocationImmediatelyRemovesReadAndWriteWithExistingJwt(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $ministry = $this->ministry(); $this->join($leader, $ministry, true);
        $id = $this->post($leader, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('DELETE', "/api/ministries/$ministry/members/".$leader['id'], $admin);
        self::assertResponseStatusCodeSame(204);
        foreach ([['GET', "/api/posts/$id", null], ['GET', "/api/admin/posts/$id", null], ['PATCH', "/api/posts/$id", ['title' => 'Revoked']], ['POST', "/api/posts/$id/unpublish", []]] as [$method, $path, $data]) {
            $this->request($method, $path, $leader, $data);
            self::assertResponseStatusCodeSame(404);
        }
        $this->request('GET', '/api/admin/posts', $leader);
        self::assertSame(0, $this->body()['pagination']['total']);
    }

    public function testInactiveMinistryHistoryIsAvailableOnlyToGlobalManagers(): void
    {
        $admin = $this->account('ADMIN'); $pastor = $this->account('PASTOR'); $leader = $this->account('LEADER');
        $ministry = $this->ministry(); $this->join($leader, $ministry, true);
        $id = $this->post($admin, ['ministry_id' => $ministry]);
        $this->request('DELETE', "/api/ministries/$ministry", $admin);
        $this->request('GET', '/api/admin/posts', $leader);
        self::assertSame(0, $this->body()['pagination']['total']);
        $this->request('GET', "/api/admin/posts/$id", $pastor);
        self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/posts/$id", $pastor, ['content' => 'History correction']);
        self::assertResponseIsSuccessful();
        $this->request('POST', "/api/posts/$id/publish", $pastor, []);
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/api/posts', $admin, ['title' => 'New', 'content' => 'Text', 'ministry_id' => $ministry]);
        self::assertResponseStatusCodeSame(409);
        $this->request('DELETE', "/api/posts/$id", $pastor);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAuditDoesNotCopyContentAndNoOpEditDoesNotWriteAudit(): void
    {
        $admin = $this->account('ADMIN');
        $id = $this->post($admin, ['title' => 'Sensitive title 7624', 'content' => 'Sensitive body 8172']);
        $this->request('PATCH', "/api/posts/$id", $admin, ['content' => 'Corrected private body 3349', 'comments_enabled' => false]);
        self::assertFalse($this->body()['post']['comments_enabled']);
        $count = (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'posts' AND entity_id = ?", [$id]);
        $this->request('PATCH', "/api/posts/$id", $admin, ['content' => 'Corrected private body 3349', 'comments_enabled' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame($count, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'posts' AND entity_id = ?", [$id]));
        $audit = json_encode($this->db->fetchAllAssociative("SELECT metadata FROM audit_logs WHERE entity_type = 'posts' AND entity_id = ?", [$id]), JSON_THROW_ON_ERROR);
        foreach (['Sensitive title 7624', 'Sensitive body 8172', 'Corrected private body 3349'] as $value) { self::assertStringNotContainsString($value, $audit); }
    }

    public function testAuditFailureRollsBackPublicationAndAudienceChange(): void
    {
        $admin = $this->account('ADMIN'); $ministry = $this->ministry();
        $id = $this->post($admin, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS']);
        $this->db->executeStatement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_post_audit CHECK (entity_type <> 'posts') NOT VALID");
        try {
            $this->request('POST', "/api/posts/$id/publish", $admin, []);
            self::assertResponseStatusCodeSame(500);
            self::assertSame('DRAFT', $this->db->fetchOne('SELECT status FROM posts WHERE id = ?', [$id]));
            self::assertNull($this->db->fetchOne('SELECT published_at FROM posts WHERE id = ?', [$id]));
            $this->request('PATCH', "/api/posts/$id", $admin, ['ministry_id' => null, 'visibility' => 'PUBLIC']);
            self::assertResponseStatusCodeSame(500);
            self::assertSame('MINISTRY_MEMBERS', $this->db->fetchOne('SELECT visibility FROM posts WHERE id = ?', [$id]));
            self::assertSame($ministry, (int) $this->db->fetchOne('SELECT ministry_id FROM posts WHERE id = ?', [$id]));
        } finally { $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_post_audit'); }
    }

    #[DataProvider('invalidPosts')]
    public function testInvalidCreationCannotOverrideAuthorOrState(array $body): void
    {
        $admin = $this->account('ADMIN');
        $this->request('POST', '/api/posts', $admin, ['title' => 'Title', 'content' => 'Content', ...$body]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM posts'));
    }

    public static function invalidPosts(): iterable
    {
        yield [['title' => null]];
        yield [['content' => str_repeat('x', 50001)]];
        yield [['ministry_id' => '1']];
        yield [['visibility' => 'MINISTRY_MEMBERS']];
        yield [['comments_enabled' => 1]];
        yield [['author_id' => 1]];
        yield [['status' => 'PUBLISHED']];
        yield [['published_at' => '2026-09-12T12:00:00Z']];
    }

    public function testMemberAnonymousAndMalformedRequestsCannotMutatePosts(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $id = $this->post($admin);
        foreach ([['POST','/api/posts',['title'=>'Title','content'=>'Content']], ['PATCH',"/api/posts/$id",['title'=>'Change']], ['DELETE',"/api/posts/$id",null], ['POST',"/api/posts/$id/publish",[]]] as [$method,$path,$body]) {
            $this->request($method,$path,null,$body); self::assertResponseStatusCodeSame(401);
            $this->request($method,$path,$member,$body); self::assertResponseStatusCodeSame(403);
        }
        $this->request('GET','/api/admin/posts',$member); self::assertResponseStatusCodeSame(403);
        foreach (['q[]=x','q=','ministry_id=0','ministry_id[]=1','visibility=PRIVATE','page=2147483648&limit=100'] as $query) {
            $this->request('GET','/api/posts?'.$query,$member); self::assertResponseStatusCodeSame(422);
        }
        $this->request('POST',"/api/posts/$id/publish",$admin,['published_at'=>'2020-01-01']); self::assertResponseStatusCodeSame(422);
        $this->request('PATCH',"/api/posts/$id",$admin,[]); self::assertResponseStatusCodeSame(422);
        $this->client->request('OPTIONS',"https://localhost/api/posts/$id",server:['HTTP_ORIGIN'=>'http://localhost:5173','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'PATCH']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('OPTIONS',"https://localhost/api/posts/$id/publish",server:['HTTP_ORIGIN'=>'http://localhost:5173','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'DELETE']);
        self::assertResponseStatusCodeSame(403);
    }

    private function account(string $role): array
    {
        $email='post-test-'.bin2hex(random_bytes(8)).'@example.test'; $password='test-only-'.bin2hex(random_bytes(8));
        $id=(int)$this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Fixture',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id",[$email,$email,password_hash($password,PASSWORD_BCRYPT,['cost'=>4]),$role]);
        $this->users[]=$id;
        $session=self::getContainer()->get(SessionService::class)->login($email,$password,AuthClientType::MOBILE);
        return ['id'=>$id,'session'=>$session->sessionId,'token'=>self::getContainer()->get(JwtService::class)->issue((string)$id,$session->sessionId)];
    }

    private function ministry(): int
    {
        $id=(int)$this->db->fetchOne("INSERT INTO ministries (name,slug,status,created_at,updated_at) VALUES ('Fixture',?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id",['post-test-'.bin2hex(random_bytes(8))]);
        $this->ministries[]=$id; return $id;
    }

    private function join(array $user,int $ministry,bool $leader=false): void
    {
        $this->db->executeStatement("INSERT INTO user_ministries (user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()))",[$user['id'],$ministry,$leader?'true':'false']);
    }

    private function post(array $actor,array $data=[],bool $publish=false): int
    {
        $this->request('POST','/api/posts',$actor,['title'=>'Fixture post','content'=>'Fixture content',...$data]);
        self::assertResponseStatusCodeSame(201); $id=$this->body()['post']['id'];
        self::assertResponseHeaderSame('Location','/api/admin/posts/'.$id);
        if ($publish) { $this->request('POST',"/api/posts/$id/publish",$actor,[]); self::assertResponseIsSuccessful(); }
        return $id;
    }

    private function request(string $method,string $path,?array $actor,?array $body=null): void
    {
        $headers=['CONTENT_TYPE'=>'application/json']; if ($actor!==null) { $headers['HTTP_AUTHORIZATION']='Bearer '.$actor['token']; }
        $this->client->request($method,'https://localhost'.$path,server:$headers,content:$body===null?null:json_encode((object)$body,JSON_THROW_ON_ERROR));
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);
    }
}
