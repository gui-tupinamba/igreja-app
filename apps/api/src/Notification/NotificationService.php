<?php

declare(strict_types=1);

namespace App\Notification;

use App\Security\AuthenticatedActor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class NotificationService
{
    public function __construct(private Connection $db) {}

    public function preferences(int $userId): array
    {
        $row = $this->db->fetchAssociative('SELECT push_enabled, church_push_enabled, ministry_push_enabled FROM notification_preferences WHERE user_id = ?', [$userId]);
        return $row ? array_map(static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOL), $row) : ['push_enabled' => true, 'church_push_enabled' => true, 'ministry_push_enabled' => true];
    }

    public function updatePreferences(int $userId, array $data): array
    {
        if ($data === []) throw new UnprocessableEntityHttpException();
        foreach ($data as $value) if (!is_bool($value)) throw new UnprocessableEntityHttpException();
        $current = [...$this->preferences($userId), ...$data];
        $this->db->executeStatement('INSERT INTO notification_preferences (user_id,push_enabled,church_push_enabled,ministry_push_enabled,updated_at) VALUES (?,?,?,?,clock_timestamp()) ON CONFLICT (user_id) DO UPDATE SET push_enabled=EXCLUDED.push_enabled,church_push_enabled=EXCLUDED.church_push_enabled,ministry_push_enabled=EXCLUDED.ministry_push_enabled,updated_at=EXCLUDED.updated_at', [$userId, $current['push_enabled'], $current['church_push_enabled'], $current['ministry_push_enabled']], [ParameterType::INTEGER,ParameterType::BOOLEAN,ParameterType::BOOLEAN,ParameterType::BOOLEAN]);
        return $current;
    }

    public function registerDevice(AuthenticatedActor $actor, array $data): array
    {
        $token = $data['expo_push_token'] ?? null; $platform = $data['platform'] ?? null;
        if (!is_string($token) || strlen($token) > 255 || preg_match('/^(Exponent|Expo)PushToken\[[A-Za-z0-9_-]+\]$/D', $token) !== 1 || !in_array($platform, ['ANDROID','IOS'], true)) throw new UnprocessableEntityHttpException();
        return $this->db->transactional(function () use ($actor, $token, $platform): array {
            $row = $this->db->fetchAssociative("INSERT INTO push_devices (user_id,session_id,expo_push_token,platform,status,last_seen_at,created_at,updated_at) VALUES (?,?,?,?,'ACTIVE',clock_timestamp(),clock_timestamp(),clock_timestamp()) ON CONFLICT (expo_push_token) DO UPDATE SET user_id=EXCLUDED.user_id,session_id=EXCLUDED.session_id,platform=EXCLUDED.platform,status='ACTIVE',last_seen_at=EXCLUDED.last_seen_at,updated_at=EXCLUDED.updated_at RETURNING id,platform,status", [$actor->userId, $actor->sessionId, $token, $platform]);
            return ['id' => (int)$row['id'], 'platform' => $row['platform'], 'status' => $row['status']];
        });
    }

    public function unregisterDevice(AuthenticatedActor $actor, array $data): void
    {
        $token = $data['expo_push_token'] ?? null;
        if (!is_string($token) || strlen($token) > 255) throw new UnprocessableEntityHttpException();
        $this->db->executeStatement("UPDATE push_devices SET status='INVALID',updated_at=clock_timestamp() WHERE expo_push_token=? AND user_id=? AND session_id=?", [$token,$actor->userId,$actor->sessionId]);
    }

    public function create(AuthenticatedActor $actor, array $data): array
    {
        $scope = $data['scope'] ?? null; $title = $this->text($data['title'] ?? null, 120); $body = $this->text($data['body'] ?? null, 1000);
        $key = $data['idempotency_key'] ?? null; $ministryId = $this->nullableId($data['ministry_id'] ?? null); $targetId = $this->nullableId($data['target_user_id'] ?? null); $route = $data['route'] ?? null;
        if (!in_array($scope, ['CHURCH','MINISTRY','USER'], true) || !is_string($key) || preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$key)!==1 || ($route !== null && (!is_string($route) || strlen($route)>255 || preg_match('#^/(posts|events|ministries)/[1-9][0-9]*$|^/(agenda|notifications)$#D',$route)!==1))) throw new UnprocessableEntityHttpException();
        if (($scope==='CHURCH' && ($ministryId||$targetId)) || ($scope==='MINISTRY' && (!$ministryId||$targetId)) || ($scope==='USER' && ($ministryId||!$targetId))) throw new UnprocessableEntityHttpException();
        $hash = hash('sha256', json_encode([$scope,$ministryId,$targetId,$title,$body,$route], JSON_THROW_ON_ERROR));
        return $this->db->transactional(function () use ($actor,$scope,$ministryId,$targetId,$title,$body,$route,$key,$hash): array {
            $user=$this->db->fetchAssociative('SELECT role,status FROM users WHERE id=? FOR UPDATE',[$actor->userId]);
            if (!$user || $user['status']!=='ACTIVE') throw new AccessDeniedHttpException();
            $this->authorizeCreate($actor->userId,$user['role'],$scope,$ministryId);
            if ($scope==='MINISTRY' && $this->db->fetchOne("SELECT 1 FROM ministries WHERE id=? AND status='ACTIVE'",[$ministryId])===false) throw new NotFoundHttpException();
            if ($scope==='USER' && $this->db->fetchOne("SELECT 1 FROM users WHERE id=? AND status='ACTIVE'",[$targetId])===false) throw new NotFoundHttpException();
            $existing=$this->db->fetchAssociative('SELECT * FROM notifications WHERE created_by_id=? AND idempotency_key=?',[$actor->userId,$key]);
            if ($existing) { if (!hash_equals($existing['payload_hash'],$hash)) throw new ConflictHttpException(); return $this->view($existing); }
            try { $n=$this->db->fetchAssociative('INSERT INTO notifications (created_by_id,scope,ministry_id,target_user_id,title,body,route,idempotency_key,payload_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?,clock_timestamp()) RETURNING *',[$actor->userId,$scope,$ministryId,$targetId,$title,$body,$route,$key,$hash]); }
            catch (UniqueConstraintViolationException) { $n=$this->db->fetchAssociative('SELECT * FROM notifications WHERE created_by_id=? AND idempotency_key=?',[$actor->userId,$key]); if (!$n || !hash_equals($n['payload_hash'],$hash)) throw new ConflictHttpException(); return $this->view($n); }
            $recipientSql = match($scope) {
                'CHURCH' => "SELECT id FROM users WHERE status='ACTIVE'",
                'USER' => "SELECT id FROM users WHERE id=".(int)$targetId." AND status='ACTIVE'",
                default => "SELECT u.id FROM users u WHERE u.status='ACTIVE' AND (u.role IN ('ADMIN','PASTOR') OR EXISTS (SELECT 1 FROM user_ministries um JOIN ministries m ON m.id=um.ministry_id AND m.status='ACTIVE' WHERE um.user_id=u.id AND um.ministry_id=".(int)$ministryId." AND um.status='ACTIVE'))",
            };
            $this->db->executeStatement('INSERT INTO notification_recipients (notification_id,user_id,created_at) SELECT ?,id,clock_timestamp() FROM ('.$recipientSql.') audience',[$n['id']]);
            $pref = $scope==='MINISTRY' ? 'ministry_push_enabled' : 'church_push_enabled';
            $this->db->executeStatement("INSERT INTO push_deliveries (notification_id,device_id,status,next_attempt_at,created_at,updated_at) SELECT ?,d.id,'PENDING',clock_timestamp(),clock_timestamp(),clock_timestamp() FROM notification_recipients nr JOIN push_devices d ON d.user_id=nr.user_id AND d.status='ACTIVE' JOIN auth_sessions s ON s.id=d.session_id AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() LEFT JOIN notification_preferences p ON p.user_id=nr.user_id WHERE nr.notification_id=? AND COALESCE(p.push_enabled,TRUE)=TRUE AND COALESCE(p.$pref,TRUE)=TRUE ON CONFLICT DO NOTHING",[$n['id'],$n['id']]);
            return $this->view($n);
        });
    }

    public function list(int $userId, int $page, int $limit): array
    {
        $where=$this->accessSql(); $params=[$userId,$userId];
        $total=(int)$this->db->fetchOne("SELECT count(*) FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id JOIN users u ON u.id=nr.user_id WHERE nr.user_id=? AND u.status='ACTIVE' AND $where",$params);
        $rows=$this->db->fetchAllAssociative("SELECT n.*,nr.read_at FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id JOIN users u ON u.id=nr.user_id WHERE nr.user_id=? AND u.status='ACTIVE' AND $where ORDER BY n.id DESC LIMIT ? OFFSET ?",[...$params,$limit,($page-1)*$limit],[ParameterType::INTEGER,ParameterType::INTEGER,ParameterType::INTEGER,ParameterType::INTEGER]);
        $unread=(int)$this->db->fetchOne("SELECT count(*) FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id JOIN users u ON u.id=nr.user_id WHERE nr.user_id=? AND nr.read_at IS NULL AND u.status='ACTIVE' AND $where",$params);
        return ['items'=>array_map(fn($r)=>[...$this->view($r),'read_at'=>$r['read_at']],$rows),'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>$total],'unread_count'=>$unread];
    }

    public function detail(int $userId,int $id): array { $row=$this->db->fetchAssociative('SELECT n.*,nr.read_at FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id JOIN users u ON u.id=nr.user_id WHERE nr.user_id=? AND n.id=? AND u.status=\'ACTIVE\' AND '.$this->accessSql(),[$userId,$id,$userId]); if(!$row) throw new NotFoundHttpException(); return [...$this->view($row),'read_at'=>$row['read_at']]; }
    public function read(int $userId,int $id): array { $this->detail($userId,$id); $this->db->executeStatement('UPDATE notification_recipients SET read_at=COALESCE(read_at,clock_timestamp()) WHERE user_id=? AND notification_id=?',[$userId,$id]); return $this->detail($userId,$id); }
    public function readAll(int $userId): int { $ids=array_column($this->list($userId,1,100)['items'],'id'); if(!$ids)return 0; return $this->db->executeStatement('UPDATE notification_recipients SET read_at=COALESCE(read_at,clock_timestamp()) WHERE user_id=? AND notification_id IN (?)',[$userId,$ids],[ParameterType::INTEGER,ArrayParameterType::INTEGER]); }

    private function accessSql(): string { return "(n.scope IN ('CHURCH','USER') OR EXISTS (SELECT 1 FROM users au WHERE au.id=? AND (au.role IN ('ADMIN','PASTOR') OR EXISTS (SELECT 1 FROM user_ministries um JOIN ministries m ON m.id=um.ministry_id AND m.status='ACTIVE' WHERE um.user_id=au.id AND um.ministry_id=n.ministry_id AND um.status='ACTIVE'))))"; }
    private function authorizeCreate(int $uid,string $role,string $scope,?int $mid): void { if(in_array($role,['ADMIN','PASTOR'],true))return; if($role==='LEADER'&&$scope==='MINISTRY'&&$this->db->fetchOne("SELECT 1 FROM user_ministries um JOIN ministries m ON m.id=um.ministry_id WHERE um.user_id=? AND um.ministry_id=? AND um.status='ACTIVE' AND um.is_leader=TRUE AND m.status='ACTIVE'",[$uid,$mid])!==false)return; throw new AccessDeniedHttpException(); }
    private function text(mixed $v,int $max): string { if(!is_string($v)||($v=trim($v))===''||mb_strlen($v)>$max)throw new UnprocessableEntityHttpException(); return $v; }
    private function nullableId(mixed $v): ?int { if($v===null)return null; if(!is_int($v)||$v<1)throw new UnprocessableEntityHttpException(); return $v; }
    private function view(array $r): array { return ['id'=>(int)$r['id'],'scope'=>$r['scope'],'ministry_id'=>$r['ministry_id']===null?null:(int)$r['ministry_id'],'target_user_id'=>$r['target_user_id']===null?null:(int)$r['target_user_id'],'title'=>$r['title'],'body'=>$r['body'],'route'=>$r['route'],'created_at'=>(new DateTimeImmutable($r['created_at']))->format(DATE_ATOM)]; }
}
