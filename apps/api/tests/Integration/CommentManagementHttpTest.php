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

final class CommentManagementHttpTest extends WebTestCase
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
        foreach ($this->users as $id) { $this->db->delete('comments', ['user_id' => $id]); }
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

    public function testAuthorLifecycleAndTerminalDeletion(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $post = $this->post($admin, [], true); $id = $this->comment($member, $post);
        self::assertSame($member['id'], $this->body()['comment']['user_id']);
        self::assertSame('VISIBLE', $this->body()['comment']['status']);
        $this->request('PATCH', "/api/posts/$post/comments/$id", $member, ['content' => "Correção\nsegunda linha"]);
        self::assertSame("Correção\nsegunda linha", $this->body()['comment']['content']);
        $this->request('GET', "/api/posts/$post/comments/$id", $admin);
        self::assertResponseIsSuccessful();
        $this->request('DELETE', "/api/posts/$post/comments/$id", $member);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('DELETED', $this->db->fetchOne('SELECT status FROM comments WHERE id = ?', [$id]));
        $this->request('GET', "/api/posts/$post/comments/$id", $member); self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $admin, ['status' => 'VISIBLE']); self::assertResponseStatusCodeSame(404);
        $this->request('GET', "/api/admin/posts/$post/comments", $admin); self::assertSame(0, $this->body()['pagination']['total']);
    }

    public function testClosedCommentsPreventCreationButAllowExistingAuthorCorrection(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $post = $this->post($admin, [], true);
        $id = $this->comment($member, $post);
        $this->request('PATCH', "/api/posts/$post", $admin, ['comments_enabled' => false]); self::assertResponseIsSuccessful();
        $this->request('POST', "/api/posts/$post/comments", $member, ['content' => 'New']); self::assertResponseStatusCodeSame(409);
        $this->request('PATCH', "/api/posts/$post/comments/$id", $member, ['content' => 'Correction']); self::assertResponseIsSuccessful();
        $this->request('DELETE', "/api/posts/$post/comments/$id", $member); self::assertResponseStatusCodeSame(204);
    }

    public function testModerationHidesFromEveryRegularReaderAndCountThenRestores(): void
    {
        $admin = $this->account('ADMIN'); $pastor = $this->account('PASTOR'); $member = $this->account('MEMBER');
        $post = $this->post($admin, [], true); $hidden = $this->comment($member, $post); $visible = $this->comment($member, $post);
        $this->request('PATCH', "/api/admin/posts/$post/comments/$hidden/status", $pastor, ['status' => 'HIDDEN']); self::assertResponseStatusCodeSame(204);
        foreach ([$admin, $pastor, $member] as $actor) {
            $this->request('GET', "/api/posts/$post/comments?limit=1", $actor);
            self::assertSame(1, $this->body()['pagination']['total']); self::assertSame([$visible], array_column($this->body()['items'], 'id'));
            $this->request('GET', "/api/posts/$post/comments?page=2&limit=1", $actor);
            self::assertSame(1, $this->body()['pagination']['total']); self::assertSame([], $this->body()['items']);
            $this->request('GET', "/api/posts/$post/comments/$hidden", $actor); self::assertResponseStatusCodeSame(404);
        }
        $this->request('PATCH', "/api/posts/$post/comments/$hidden", $member, ['content' => 'Bypass']); self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', "/api/posts/$post/comments/$hidden", $member); self::assertResponseStatusCodeSame(404);
        $this->request('GET', "/api/admin/posts/$post/comments?limit=1", $pastor);
        self::assertSame(2, $this->body()['pagination']['total']); self::assertSame($hidden, $this->body()['items'][0]['id']);
        $this->request('PATCH', "/api/admin/posts/$post/comments/$hidden/status", $admin, ['status' => 'VISIBLE']); self::assertResponseStatusCodeSame(204);
        $this->request('GET', "/api/posts/$post/comments", $member);
        self::assertSame([$hidden, $visible], array_column($this->body()['items'], 'id'));
    }

    public function testParentIdAndAuthorshipNeverGrantAccessToPrivatePost(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $other = $this->account('MEMBER');
        $ministry = $this->ministry(); $this->join($member, $ministry);
        $post = $this->post($admin, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $another = $this->post($admin, [], true); $id = $this->comment($member, $post);
        foreach ([['GET', null], ['PATCH', ['content' => 'Wrong parent']], ['DELETE', null]] as [$method, $data]) {
            $this->request($method, "/api/posts/$another/comments/$id", $member, $data); self::assertResponseStatusCodeSame(404);
        }
        $this->request('PATCH', "/api/admin/posts/$another/comments/$id/status", $admin, ['status' => 'HIDDEN']); self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', "/api/ministries/$ministry/members/".$member['id'], $admin); self::assertResponseStatusCodeSame(204);
        foreach ([$member, $other] as $actor) {
            foreach ([['GET', '', null], ['POST', '', ['content' => 'Forbidden']], ['GET', "/$id", null], ['PATCH', "/$id", ['content' => 'Forbidden']], ['DELETE', "/$id", null]] as [$method, $suffix, $data]) {
                $this->request($method, "/api/posts/$post/comments$suffix", $actor, $data); self::assertResponseStatusCodeSame(404);
            }
        }
    }

    public function testOtherAuthorsAndMinistryLeadersCannotEditOrModerate(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $member = $this->account('MEMBER');
        $ministry = $this->ministry(); $this->join($leader, $ministry, true);
        $post = $this->post($admin, ['ministry_id' => $ministry], true); $id = $this->comment($member, $post);
        foreach ([$admin, $leader] as $actor) {
            $this->request('PATCH', "/api/posts/$post/comments/$id", $actor, ['content' => 'Impersonation']); self::assertResponseStatusCodeSame(403);
            $this->request('DELETE', "/api/posts/$post/comments/$id", $actor); self::assertResponseStatusCodeSame(403);
        }
        foreach ([$member, $leader] as $actor) {
            $this->request('GET', "/api/admin/posts/$post/comments", $actor); self::assertResponseStatusCodeSame(403);
            foreach ([$post, 2147483647] as $parent) {
                $this->request('PATCH', "/api/admin/posts/$parent/comments/$id/status", $actor, ['status' => 'HIDDEN']); self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testUnpublishedAndInactiveParentsRequireGlobalMaintenance(): void
    {
        $admin = $this->account('ADMIN'); $pastor = $this->account('PASTOR'); $member = $this->account('MEMBER');
        $ministry = $this->ministry(); $post = $this->post($admin, ['ministry_id' => $ministry], true); $id = $this->comment($member, $post);
        foreach (['DRAFT', 'ARCHIVED', 'PUBLISHED'] as $status) {
            $this->db->update('posts', ['status' => $status], ['id' => $post]);
            if ($status === 'PUBLISHED') { $this->request('DELETE', "/api/ministries/$ministry", $admin); self::assertResponseStatusCodeSame(204); }
            foreach ([$member, $admin] as $actor) {
                $this->request('GET', "/api/posts/$post/comments", $actor); self::assertResponseStatusCodeSame(404);
                $this->request('POST', "/api/posts/$post/comments", $actor, ['content' => 'Forbidden']); self::assertResponseStatusCodeSame(404);
            }
            $this->request('GET', "/api/admin/posts/$post/comments", $pastor); self::assertResponseIsSuccessful();
            $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $pastor, ['status' => 'HIDDEN']); self::assertResponseStatusCodeSame(204);
            $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $pastor, ['status' => 'VISIBLE']); self::assertResponseStatusCodeSame(204);
        }
    }

    public function testAuditNoOpsAndRollbackDoNotCopyCommentText(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $post = $this->post($admin, [], true); $id = $this->comment($member, $post);
        $this->request('PATCH', "/api/posts/$post/comments/$id", $member, ['content' => 'Secret correction 72184']); self::assertResponseIsSuccessful();
        $count = (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'comments'");
        $this->request('PATCH', "/api/posts/$post/comments/$id", $member, ['content' => 'Secret correction 72184']); self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $admin, ['status' => 'VISIBLE']); self::assertResponseStatusCodeSame(204);
        self::assertSame($count, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'comments'"));
        self::assertStringNotContainsString('Secret correction 72184', json_encode($this->db->fetchAllAssociative("SELECT metadata FROM audit_logs WHERE entity_type = 'comments'"), JSON_THROW_ON_ERROR));
        $this->db->executeStatement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_comment_audit CHECK (entity_type <> 'comments') NOT VALID");
        try {
            $this->request('POST', "/api/posts/$post/comments", $member, ['content' => 'Rollback']); self::assertResponseStatusCodeSame(500);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM comments WHERE post_id = ?', [$post]));
            $this->request('PATCH', "/api/posts/$post/comments/$id", $member, ['content' => 'Rollback']); self::assertResponseStatusCodeSame(500);
            self::assertSame('Secret correction 72184', $this->db->fetchOne('SELECT content FROM comments WHERE id = ?', [$id]));
            $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $admin, ['status' => 'HIDDEN']); self::assertResponseStatusCodeSame(500);
            $this->request('DELETE', "/api/posts/$post/comments/$id", $member); self::assertResponseStatusCodeSame(500);
            self::assertSame('VISIBLE', $this->db->fetchOne('SELECT status FROM comments WHERE id = ?', [$id]));
        } finally { $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_comment_audit'); }
    }

    #[DataProvider('invalidComments')]
    public function testInvalidCommentCannotOverrideOwnershipOrState(array $data): void
    {
        $admin = $this->account('ADMIN'); $post = $this->post($admin, [], true);
        $this->request('POST', "/api/posts/$post/comments", $admin, $data); self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM comments WHERE post_id = ?', [$post]));
    }

    public static function invalidComments(): iterable
    {
        yield [[]]; yield [['content' => null]]; yield [['content' => '   ']]; yield [['content' => str_repeat('á', 5001)]];
        yield [['content' => "text\0"]]; yield [['content' => ['text']]]; yield [['content' => 'Text', 'user_id' => 1]];
        yield [['content' => 'Text', 'post_id' => 1]]; yield [['content' => 'Text', 'status' => 'VISIBLE']];
    }

    public function testAuthenticationPaginationValidationAndCors(): void
    {
        $admin = $this->account('ADMIN'); $post = $this->post($admin, [], true); $id = $this->comment($admin, $post);
        foreach ([['GET', "/api/posts/$post/comments", null], ['POST', "/api/posts/$post/comments", ['content' => 'Text']], ['PATCH', "/api/posts/$post/comments/$id", ['content' => 'Text']], ['DELETE', "/api/posts/$post/comments/$id", null], ['PATCH', "/api/admin/posts/$post/comments/$id/status", ['status' => 'HIDDEN']]] as [$method, $path, $data]) {
            $this->request($method, $path, null, $data); self::assertResponseStatusCodeSame(401);
        }
        foreach (['page=0', 'limit=101', 'status=HIDDEN', 'page[]=1'] as $query) {
            $this->request('GET', "/api/posts/$post/comments?$query", $admin); self::assertResponseStatusCodeSame(422);
        }
        $this->request('PATCH', "/api/admin/posts/$post/comments/$id/status", $admin, ['status' => 'ACTIVE']); self::assertResponseStatusCodeSame(422);
        $this->request('POST', "/api/posts/$post/comments", $admin, ['content' => str_repeat('á', 5000)]); self::assertResponseStatusCodeSame(201);
        foreach (["/api/posts/$post/comments/$id", "/api/admin/posts/$post/comments/$id/status"] as $path) {
            $this->client->request('OPTIONS', 'https://localhost'.$path, server: ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH']); self::assertResponseStatusCodeSame(204);
        }
    }

    private function comment(array $actor, int $post): int
    {
        $this->request('POST', "/api/posts/$post/comments", $actor, ['content' => 'Fixture comment']);
        self::assertResponseStatusCodeSame(201); $id = $this->body()['comment']['id'];
        self::assertResponseHeaderSame('Location', "/api/posts/$post/comments/$id");
        return $id;
    }

    // Fixture helpers below operate only on the isolated test database.
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
