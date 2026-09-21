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
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EventManagementHttpTest extends WebTestCase
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
            $this->db->delete('events', ['created_by' => $id]);
            $this->db->delete('user_ministries', ['user_id' => $id]);
        }
        foreach ($this->ministries as $id) { $this->db->delete('ministries', ['id' => $id]); }
        foreach ($this->users as $id) { $this->db->delete('users', ['id' => $id]); }
        $this->db?->close();
        parent::tearDown();
    }

    public function testLeadershipScopeAppliesToCreationAdminSearchAndMutations(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER');
        $first = $this->ministry(); $second = $this->ministry();
        $this->join($leader, $first, true); $this->join($leader, $second);
        $own = $this->event($leader, ['ministry_id' => $first]);
        $foreign = $this->event($admin, ['ministry_id' => $second]);
        $general = $this->event($admin);
        $this->request('GET', '/api/admin/events?status=DRAFT&limit=1', $leader);
        self::assertSame(1, $this->body()['pagination']['total']);
        self::assertSame($own, $this->body()['items'][0]['id']);
        foreach ([$foreign, $general] as $id) {
            $this->request('GET', "/api/admin/events/$id", $leader);
            self::assertResponseStatusCodeSame(404);
            $this->request('PATCH', "/api/events/$id", $leader, ['title' => 'Forbidden']);
            self::assertResponseStatusCodeSame(404);
            $this->request('POST', "/api/events/$id/publish", $leader, []);
            self::assertResponseStatusCodeSame(404);
            $this->request('DELETE', "/api/events/$id", $leader);
            self::assertResponseStatusCodeSame(404);
        }
        foreach ([null, $second] as $ministry) {
            $this->request('POST', '/api/events', $leader, ['title' => 'Forbidden', 'starts_at' => '2026-10-04T23:00:00Z', 'description' => 'Text', 'ministry_id' => $ministry]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testMovingEventRequiresBothScopesAndAuthorDoesNotRetainAuthority(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER');
        $first = $this->ministry(); $second = $this->ministry(); $third = $this->ministry();
        $this->join($leader, $first, true); $this->join($leader, $second, true);
        $id = $this->event($leader, ['ministry_id' => $first, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('PATCH', "/api/events/$id", $leader, ['ministry_id' => $third]);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', "/api/events/$id", $leader, ['ministry_id' => null, 'visibility' => 'PUBLIC']);
        self::assertResponseStatusCodeSame(403);
        $this->request('PATCH', "/api/events/$id", $leader, ['ministry_id' => $second]);
        self::assertSame($second, $this->body()['event']['ministry_id']);
        $this->request('PATCH', "/api/events/$id", $admin, ['ministry_id' => $third]);
        self::assertSame($leader['id'], $this->body()['event']['created_by']);
        $this->request('PATCH', "/api/events/$id", $leader, ['ministry_id' => $first]);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', "/api/events/$id", $leader);
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangingVisibilityOrRemovingMinistryValidatesMergedAudience(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $ministry = $this->ministry();
        $id = $this->event($admin, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('GET', "/api/events/$id", $member);
        self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', "/api/events/$id", $admin, ['ministry_id' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($ministry, (int) $this->db->fetchOne('SELECT ministry_id FROM events WHERE id = ?', [$id]));
        $this->request('PATCH', "/api/events/$id", $admin, ['ministry_id' => null, 'visibility' => 'PUBLIC']);
        self::assertResponseIsSuccessful();
        $this->request('GET', "/api/events/$id", $member);
        self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/events/$id", $admin, ['visibility' => 'MINISTRY_MEMBERS']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMembershipRevocationImmediatelyRemovesReadAndWriteWithExistingJwt(): void
    {
        $admin = $this->account('ADMIN'); $leader = $this->account('LEADER'); $ministry = $this->ministry(); $this->join($leader, $ministry, true);
        $id = $this->event($leader, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS'], true);
        $this->request('DELETE', "/api/ministries/$ministry/members/".$leader['id'], $admin);
        self::assertResponseStatusCodeSame(204);
        foreach ([['GET', "/api/events/$id", null], ['GET', "/api/admin/events/$id", null], ['PATCH', "/api/events/$id", ['title' => 'Revoked']], ['POST', "/api/events/$id/unpublish", []]] as [$method, $path, $data]) {
            $this->request($method, $path, $leader, $data);
            self::assertResponseStatusCodeSame(404);
        }
        $this->request('GET', '/api/admin/events', $leader);
        self::assertSame(0, $this->body()['pagination']['total']);
    }

    public function testInactiveMinistryHistoryIsAvailableOnlyToGlobalManagers(): void
    {
        $admin = $this->account('ADMIN'); $pastor = $this->account('PASTOR'); $leader = $this->account('LEADER');
        $ministry = $this->ministry(); $this->join($leader, $ministry, true);
        $id = $this->event($admin, ['ministry_id' => $ministry]);
        $this->request('DELETE', "/api/ministries/$ministry", $admin);
        $this->request('GET', '/api/admin/events', $leader);
        self::assertSame(0, $this->body()['pagination']['total']);
        $this->request('GET', "/api/admin/events/$id", $pastor);
        self::assertResponseIsSuccessful();
        $this->request('PATCH', "/api/events/$id", $pastor, ['description' => 'History correction']);
        self::assertResponseIsSuccessful();
        $this->request('POST', "/api/events/$id/publish", $pastor, []);
        self::assertResponseStatusCodeSame(409);
        $this->request('POST', '/api/events', $admin, ['title' => 'New', 'starts_at' => '2026-10-04T23:00:00Z', 'description' => 'Text', 'ministry_id' => $ministry]);
        self::assertResponseStatusCodeSame(409);
        $this->request('DELETE', "/api/events/$id", $pastor);
        self::assertResponseStatusCodeSame(204);
    }

    public function testLifecycleDatesAndCancellationDoNotPublishDrafts(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER');
        $id = $this->event($admin, ['starts_at'=>'2026-10-04T19:00:00-04:00','ends_at'=>'2026-10-04T20:30:00-04:00','location'=>'Templo','address'=>'Rua Central']);
        self::assertSame('2026-10-04T23:00:00Z', $this->body()['event']['starts_at']);
        self::assertSame('2026-10-05T00:30:00Z', $this->body()['event']['ends_at']);
        self::assertSame($admin['id'], $this->body()['event']['created_by']);
        self::assertSame('DRAFT', $this->body()['event']['status']);
        $this->request('POST', "/api/events/$id/cancel", $admin, []); self::assertResponseStatusCodeSame(409);
        $this->request('GET', "/api/events/$id", $member); self::assertResponseStatusCodeSame(404);
        $this->request('POST', "/api/events/$id/publish", $admin, []); self::assertSame('PUBLISHED', $this->body()['event']['status']);
        $this->request('POST', "/api/events/$id/cancel", $admin, []); self::assertSame('CANCELLED', $this->body()['event']['status']);
        $this->request('GET', '/api/events?status=CANCELLED', $member); self::assertSame([$id], array_column($this->body()['items'], 'id'));
        $this->request('DELETE', "/api/events/$id", $admin); self::assertResponseStatusCodeSame(204);
        $this->request('POST', "/api/events/$id/cancel", $admin, []); self::assertResponseStatusCodeSame(409);
        $this->request('GET', "/api/events/$id", $member); self::assertResponseStatusCodeSame(404);
        $this->request('GET', '/api/admin/events?status=ARCHIVED', $admin); self::assertSame([$id], array_column($this->body()['items'], 'id'));
        $this->request('POST', "/api/events/$id/publish", $admin, []); self::assertResponseIsSuccessful();
        $this->request('POST', "/api/events/$id/unpublish", $admin, []); self::assertSame('DRAFT', $this->body()['event']['status']);
    }

    public function testPeriodOverlapBoundariesAndPrivacyAreFilteredBeforePagination(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $ministry = $this->ministry();
        $overlap = $this->event($admin, ['starts_at'=>'2026-10-03T20:00:00Z','ends_at'=>'2026-10-04T02:00:00Z'], true);
        $point = $this->event($admin, ['starts_at'=>'2026-10-04T00:00:00Z'], true);
        $tie = $this->event($admin, ['starts_at'=>'2026-10-04T00:00:00Z'], true);
        $this->event($admin, ['starts_at'=>'2026-10-05T00:00:00Z'], true);
        $this->event($admin, ['starts_at'=>'2026-10-03T19:00:00Z','ends_at'=>'2026-10-03T23:59:59Z'], true);
        $secret = $this->event($admin, ['title'=>'Confidential gathering','ministry_id'=>$ministry,'visibility'=>'MINISTRY_MEMBERS','starts_at'=>'2026-10-04T00:00:00Z'], true);
        $this->request('POST', "/api/events/$secret/cancel", $admin, []); self::assertResponseIsSuccessful();
        $query = http_build_query(['from'=>'2026-10-03T20:00:00-04:00','to'=>'2026-10-04T20:00:00-04:00']);
        foreach ([1=>$overlap, 2=>$point, 3=>$tie] as $page=>$expected) {
            $this->request('GET', "/api/events?$query&limit=1&page=$page", $member);
            self::assertSame(3, $this->body()['pagination']['total']); self::assertSame([$expected], array_column($this->body()['items'], 'id'));
        }
        $this->request('GET', "/api/events?$query&limit=1&page=4", $member);
        self::assertSame(3, $this->body()['pagination']['total']); self::assertSame([], $this->body()['items']);
        foreach (['q=Confidential', "ministry_id=$ministry&visibility=MINISTRY_MEMBERS", 'status=CANCELLED', 'q=%25', 'q=%27%20OR%201%3D1--'] as $filter) {
            $this->request('GET', '/api/events?'.$filter, $member);
            self::assertSame(0, $this->body()['pagination']['total']); self::assertSame([], $this->body()['items']);
        }
        $this->request('GET', "/api/events/$secret", $member); self::assertResponseStatusCodeSame(404);
        $this->join($member, $ministry);
        $this->request('GET', '/api/events?status=CANCELLED', $member); self::assertSame([$secret], array_column($this->body()['items'], 'id'));
        $this->request('GET', "/api/ministries/$ministry/events/$secret", $member); self::assertResponseIsSuccessful();
        $other = $this->ministry();
        $this->request('GET', "/api/ministries/$other/events/$secret", $admin); self::assertResponseStatusCodeSame(404);
    }

    public function testReschedulingValidatesMergedDatesAndEquivalentOffsetsAreNoOps(): void
    {
        $admin = $this->account('ADMIN'); $id = $this->event($admin, ['starts_at'=>'2026-10-04T19:00:00-04:00','ends_at'=>'2026-10-05T00:30:00Z']);
        $count = (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'events'");
        $this->request('PATCH', "/api/events/$id", $admin, ['starts_at'=>'2026-10-04T23:00:00Z','ends_at'=>'2026-10-04T20:30:00-04:00']); self::assertResponseIsSuccessful();
        self::assertSame($count, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE entity_type = 'events'"));
        foreach ([['starts_at'=>'2026-10-06T00:00:00Z'], ['ends_at'=>'2026-10-04T22:59:59Z']] as $data) {
            $this->request('PATCH', "/api/events/$id", $admin, $data); self::assertResponseStatusCodeSame(422);
        }
        $this->request('PATCH', "/api/events/$id", $admin, ['starts_at'=>'2026-10-06T00:00:00Z','ends_at'=>null, 'description'=>null, 'location'=>null, 'address'=>null]);
        self::assertResponseIsSuccessful(); self::assertNull($this->body()['event']['ends_at']);
        $this->request('PATCH', "/api/events/$id", $admin, ['ends_at'=>'2026-10-06T00:00:00Z']); self::assertResponseIsSuccessful();
    }

    public function testAuditFailureRollsBackDatesAudienceAndStateWithoutCopyingText(): void
    {
        $admin = $this->account('ADMIN'); $ministry = $this->ministry();
        $id = $this->event($admin, ['description'=>'Sensitive event 48721','address'=>'Private address 37112','ministry_id'=>$ministry,'visibility'=>'MINISTRY_MEMBERS'], true);
        $audit = json_encode($this->db->fetchAllAssociative("SELECT metadata FROM audit_logs WHERE entity_type = 'events'"), JSON_THROW_ON_ERROR);
        foreach (['Sensitive event 48721','Private address 37112'] as $text) { self::assertStringNotContainsString($text, $audit); }
        $before = $this->db->fetchAssociative('SELECT * FROM events WHERE id = ?', [$id]);
        $this->db->executeStatement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_event_audit CHECK (entity_type <> 'events') NOT VALID");
        try {
            $this->request('POST', '/api/events', $admin, ['title'=>'Rollback','starts_at'=>'2026-10-01T00:00:00Z']); self::assertResponseStatusCodeSame(500);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM events'));
            $this->request('PATCH', "/api/events/$id", $admin, ['starts_at'=>'2026-11-01T00:00:00Z','ministry_id'=>null,'visibility'=>'PUBLIC']); self::assertResponseStatusCodeSame(500);
            $this->request('POST', "/api/events/$id/cancel", $admin, []); self::assertResponseStatusCodeSame(500);
            $this->request('DELETE', "/api/events/$id", $admin); self::assertResponseStatusCodeSame(500);
            self::assertSame($before, $this->db->fetchAssociative('SELECT * FROM events WHERE id = ?', [$id]));
        } finally { $this->db->executeStatement('ALTER TABLE audit_logs DROP CONSTRAINT test_reject_event_audit'); }
    }

    public function testProtectedImageUploadIsAuditedAndFollowsCurrentEventAccess(): void
    {
        $admin = $this->account('ADMIN');
        $member = $this->account('MEMBER');
        $outsider = $this->account('MEMBER');
        $ministry = $this->ministry();
        $this->join($member, $ministry);
        $id = $this->event($admin, ['ministry_id' => $ministry, 'visibility' => 'MINISTRY_MEMBERS']);
        $path = tempnam(sys_get_temp_dir(), 'event-image-');
        self::assertNotFalse($path);
        $source = imagecreatetruecolor(32, 16);
        self::assertNotFalse($source);
        imagefill($source, 0, 0, imagecolorallocate($source, 34, 91, 65));
        imagepng($source, $path);
        imagedestroy($source);
        try {
            $this->client->request('POST', "https://localhost/api/events/$id/images", files: [
                'file' => new UploadedFile($path, 'agenda.png', 'image/png', null, true),
            ], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin['token']]);
        } finally {
            @unlink($path);
        }
        self::assertResponseStatusCodeSame(201);
        $imageId = $this->body()['image']['id'];
        self::assertSame(1, (int) $this->db->fetchOne(
            "SELECT count(*) FROM audit_logs WHERE entity_type = 'events' AND entity_id = ? AND action = 'event.image_uploaded'",
            [$id],
        ));
        $this->request('POST', "/api/events/$id/publish", $admin, []);

        $this->request('GET', "/api/events/$id/images/$imageId/detail", $member);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
        $this->request('GET', "/api/events/$id/images/$imageId/detail", $outsider);
        self::assertResponseStatusCodeSame(404);
        $other = $this->event($admin);
        $this->request('GET', "/api/events/$other/images/$imageId/detail", $admin);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', "/api/ministries/$ministry/members/".$member['id'], $admin);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', "/api/events/$id/images/$imageId/detail", $member);
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('invalidEvents')]
    public function testInvalidDatesAndFieldsCannotOverrideCreatorOrState(array $data): void
    {
        $admin = $this->account('ADMIN');
        $this->request('POST', '/api/events', $admin, ['title'=>'Event','starts_at'=>'2026-10-04T23:00:00Z', ...$data]);
        self::assertResponseStatusCodeSame(422); self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM events'));
    }

    public static function invalidEvents(): iterable
    {
        foreach ([null, '2026-02-30T12:00:00Z', '2026-10-04', '2026-10-04T23:00:00', 'tomorrow', '2026-10-04T24:00:00Z', '2026-10-04T23:00:60Z', '2026-10-04T23:00:00+15:00', '0000-01-01T00:00:00Z'] as $date) { yield [['starts_at'=>$date]]; }
        yield [['ends_at'=>'2026-10-04T22:59:59Z']]; yield [['created_by'=>1]]; yield [['status'=>'PUBLISHED']];
        yield [['visibility'=>'MINISTRY_MEMBERS']]; yield [['ministry_id'=>'1']]; yield [['title'=>str_repeat('x',181)]];
        yield [['description'=>str_repeat('x',50001)]]; yield [['location'=>str_repeat('x',181)]]; yield [['address'=>"Text\0"]];
    }

    public function testAuthenticationFiltersAndCors(): void
    {
        $admin = $this->account('ADMIN'); $member = $this->account('MEMBER'); $id = $this->event($admin);
        foreach ([['POST','/api/events',['title'=>'New','starts_at'=>'2026-10-04T23:00:00Z']], ['PATCH',"/api/events/$id",['title'=>'Change']], ['DELETE',"/api/events/$id",null], ['POST',"/api/events/$id/cancel",[]], ['POST',"/api/events/$id/publish",[]]] as [$method,$path,$body]) {
            $this->request($method,$path,null,$body); self::assertResponseStatusCodeSame(401);
            $this->request($method,$path,$member,$body); self::assertResponseStatusCodeSame(403);
        }
        $this->request('GET','/api/admin/events',$member); self::assertResponseStatusCodeSame(403);
        foreach (['from=2026-10-04','to[]=2026-10-05','from=2026-10-04T00:00:00Z&to=2026-10-04T00:00:00Z','status=DRAFT','limit=101','ministry_id=0','q='] as $query) {
            $this->request('GET','/api/events?'.$query,$member); self::assertResponseStatusCodeSame(422);
        }
        $this->request('PATCH',"/api/events/$id",$admin,[]); self::assertResponseStatusCodeSame(422);
        $this->request('POST',"/api/events/$id/cancel",$admin,['status'=>'CANCELLED']); self::assertResponseStatusCodeSame(422);
        $this->client->request('OPTIONS',"https://localhost/api/events/$id",server:['HTTP_ORIGIN'=>'http://localhost:5173','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'PATCH']); self::assertResponseStatusCodeSame(204);
        $this->client->request('OPTIONS',"https://localhost/api/events/$id/cancel",server:['HTTP_ORIGIN'=>'http://localhost:5173','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'DELETE']); self::assertResponseStatusCodeSame(403);
    }


    private function account(string $role): array
    {
        $email='event-test-'.bin2hex(random_bytes(8)).'@example.test'; $password='test-only-'.bin2hex(random_bytes(8));
        $id=(int)$this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Fixture',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id",[$email,$email,password_hash($password,PASSWORD_BCRYPT,['cost'=>4]),$role]);
        $this->users[]=$id;
        $session=self::getContainer()->get(SessionService::class)->login($email,$password,AuthClientType::MOBILE);
        return ['id'=>$id,'session'=>$session->sessionId,'token'=>self::getContainer()->get(JwtService::class)->issue((string)$id,$session->sessionId)];
    }

    private function ministry(): int
    {
        $id=(int)$this->db->fetchOne("INSERT INTO ministries (name,slug,status,created_at,updated_at) VALUES ('Fixture',?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id",['event-test-'.bin2hex(random_bytes(8))]);
        $this->ministries[]=$id; return $id;
    }

    private function join(array $user,int $ministry,bool $leader=false): void
    {
        $this->db->executeStatement("INSERT INTO user_ministries (user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp()))",[$user['id'],$ministry,$leader?'true':'false']);
    }

    private function event(array $actor,array $data=[],bool $publish=false): int
    {
        $this->request('POST','/api/events',$actor,['title'=>'Fixture event','starts_at'=>'2026-10-04T23:00:00Z','description'=>'Fixture content',...$data]);
        self::assertResponseStatusCodeSame(201); $id=$this->body()['event']['id'];
        self::assertResponseHeaderSame('Location','/api/admin/events/'.$id);
        if ($publish) { $this->request('POST',"/api/events/$id/publish",$actor,[]); self::assertResponseIsSuccessful(); }
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
