<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\SessionService;
use App\Enum\AuthClientType;
use App\Security\JwtService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationHttpTest extends WebTestCase
{
    private KernelBrowser $client; private ?Connection $db=null; private array $users=[]; private array $ministries=[];
    protected function setUp():void{parent::setUp();if(getenv('RUN_DATABASE_TESTS')!=='1')self::markTestSkipped('Requires isolated PostgreSQL.');$this->client=self::createClient();$this->db=DriverManager::getConnection(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams());self::assertStringEndsWith('_test',(string)$this->db->fetchOne('SELECT current_database()'));}
    protected function tearDown():void{if($this->users){$t=[ArrayParameterType::INTEGER];$this->db->executeStatement('DELETE FROM notifications WHERE created_by_id IN (?)',[$this->users],$t);$this->db->executeStatement('DELETE FROM notification_preferences WHERE user_id IN (?)',[$this->users],$t);$this->db->executeStatement('DELETE FROM refresh_tokens WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id IN (?))',[$this->users],$t);$this->db->executeStatement('DELETE FROM auth_sessions WHERE user_id IN (?)',[$this->users],$t);$this->db->executeStatement('DELETE FROM user_ministries WHERE user_id IN (?)',[$this->users],$t);}foreach($this->ministries as $id)$this->db->delete('ministries',['id'=>$id]);foreach($this->users as $id)$this->db->delete('users',['id'=>$id]);$this->db?->close();parent::tearDown();}

    public function testChurchInboxReadPreferencesAndIdempotency():void{
        $admin=$this->account('ADMIN');$member=$this->account('MEMBER');$payload=['scope'=>'CHURCH','title'=>'Encontro geral','body'=>'Confira os detalhes no aplicativo.','route'=>'/agenda','idempotency_key'=>'church-notice-123456'];
        $this->request('POST','/api/admin/notifications',$admin,$payload);self::assertResponseStatusCodeSame(201);$id=$this->body()['notification']['id'];
        $this->request('POST','/api/admin/notifications',$admin,$payload);self::assertResponseStatusCodeSame(201);self::assertSame($id,$this->body()['notification']['id']);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM notifications WHERE id=?',[$id]));
        $this->request('GET','/api/notifications',$member);self::assertSame(1,$this->body()['unread_count']);self::assertSame($id,$this->body()['items'][0]['id']);
        $this->request('POST',"/api/notifications/$id/read",$member,[]);self::assertNotNull($this->body()['notification']['read_at']);
        $this->request('PATCH','/api/notifications/preferences',$member,['push_enabled'=>false]);self::assertFalse($this->body()['preferences']['push_enabled']);
        $this->request('POST','/api/admin/notifications',$admin,[...$payload,'body'=>'Different']);self::assertResponseStatusCodeSame(409);
    }

    public function testMinistryVisibilityAndLeadershipAreRevalidated():void{
        $admin=$this->account('ADMIN');$leader=$this->account('LEADER');$member=$this->account('MEMBER');$outsider=$this->account('MEMBER');$ministry=$this->ministry();$this->join($leader,$ministry,true);$this->join($member,$ministry);
        $payload=['scope'=>'MINISTRY','ministry_id'=>$ministry,'title'=>'Escala','body'=>'Uma nova escala está disponível.','idempotency_key'=>'ministry-notice-123'];
        $this->request('POST','/api/admin/notifications',$leader,$payload);self::assertResponseStatusCodeSame(201);$id=$this->body()['notification']['id'];
        $this->request('GET','/api/notifications',$member);self::assertSame($id,$this->body()['items'][0]['id']);$this->request('GET','/api/notifications',$outsider);self::assertSame(0,$this->body()['pagination']['total']);
        $this->db->executeStatement("UPDATE user_ministries SET status='INACTIVE',left_at=clock_timestamp() WHERE user_id=? AND ministry_id=?",[$member['id'],$ministry]);
        $this->request('GET',"/api/notifications/$id",$member);self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement("UPDATE user_ministries SET is_leader=FALSE WHERE user_id=? AND ministry_id=?",[$leader['id'],$ministry]);
        $this->request('POST','/api/admin/notifications',$leader,[...$payload,'idempotency_key'=>'ministry-notice-456']);self::assertResponseStatusCodeSame(403);
        $this->request('POST','/api/admin/notifications',$admin,[...$payload,'idempotency_key'=>'ministry-notice-789']);self::assertResponseStatusCodeSame(201);
    }

    public function testDeviceMovesToCurrentSessionAndMemberCannotSend():void{
        $first=$this->account('MEMBER');$second=$this->account('MEMBER');$token='ExpoPushToken[test_token_123456789]';
        $this->request('POST','/api/notifications/devices',$first,['expo_push_token'=>$token,'platform'=>'ANDROID']);self::assertResponseStatusCodeSame(201);self::assertSame($first['id'],(int)$this->db->fetchOne('SELECT user_id FROM push_devices WHERE expo_push_token=?',[$token]));
        $this->request('POST','/api/notifications/devices',$second,['expo_push_token'=>$token,'platform'=>'ANDROID']);self::assertResponseStatusCodeSame(201);self::assertSame($second['id'],(int)$this->db->fetchOne('SELECT user_id FROM push_devices WHERE expo_push_token=?',[$token]));
        $this->request('POST','/api/admin/notifications',$second,['scope'=>'CHURCH','title'=>'No','body'=>'No','idempotency_key'=>'member-forbidden-123']);self::assertResponseStatusCodeSame(403);
        $this->request('POST','/api/notifications/devices/unregister',$second,['expo_push_token'=>$token]);self::assertResponseStatusCodeSame(204);self::assertSame('INVALID',$this->db->fetchOne('SELECT status FROM push_devices WHERE expo_push_token=?',[$token]));
    }

    private function account(string $role):array{$email='notification-'.bin2hex(random_bytes(8)).'@example.test';$password='test-only-'.bin2hex(random_bytes(8));$id=(int)$this->db->fetchOne("INSERT INTO users (name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES ('Fixture',?,?,?,?,'ACTIVE',date_trunc('second',clock_timestamp()),date_trunc('second',clock_timestamp())) RETURNING id",[$email,$email,password_hash($password,PASSWORD_BCRYPT,['cost'=>4]),$role]);$this->users[]=$id;$session=self::getContainer()->get(SessionService::class)->login($email,$password,AuthClientType::MOBILE);return ['id'=>$id,'token'=>self::getContainer()->get(JwtService::class)->issue((string)$id,$session->sessionId)];}
    private function ministry():int{$id=(int)$this->db->fetchOne("INSERT INTO ministries (name,slug,status,created_at,updated_at) VALUES ('Fixture',?,'ACTIVE',clock_timestamp(),clock_timestamp()) RETURNING id",['notification-'.bin2hex(random_bytes(6))]);$this->ministries[]=$id;return $id;}
    private function join(array $u,int $m,bool $leader=false):void{$this->db->executeStatement("INSERT INTO user_ministries (user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,'ACTIVE',clock_timestamp(),clock_timestamp(),clock_timestamp())",[$u['id'],$m,$leader?'true':'false']);}
    private function request(string $method,string $path,?array $actor,?array $body=null):void{$headers=['CONTENT_TYPE'=>'application/json'];if($actor)$headers['HTTP_AUTHORIZATION']='Bearer '.$actor['token'];$this->client->request($method,'https://localhost'.$path,server:$headers,content:$body===null?null:json_encode((object)$body,JSON_THROW_ON_ERROR));}
    private function body():array{return json_decode($this->client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);}
}
