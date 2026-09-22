<?php

declare(strict_types=1);

namespace App\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PushDeliveryService
{
    public function __construct(private Connection $db, private ExpoPushGateway $gateway) {}
    public function enabled(): bool { return $this->gateway->enabled(); }

    public function deliver(int $limit=100): array
    {
        if(!$this->enabled()) return ['sent'=>0,'retried'=>0,'failed'=>0,'disabled'=>true];
        return $this->db->transactional(function()use($limit):array{
            $sql="SELECT pd.id,pd.attempts,d.id device_id,d.expo_push_token,n.id notification_id FROM push_deliveries pd JOIN notifications n ON n.id=pd.notification_id JOIN push_devices d ON d.id=pd.device_id AND d.status='ACTIVE' JOIN users u ON u.id=d.user_id AND u.status='ACTIVE' JOIN auth_sessions s ON s.id=d.session_id AND s.user_id=d.user_id AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() JOIN notification_recipients nr ON nr.notification_id=n.id AND nr.user_id=d.user_id WHERE pd.status IN ('PENDING','RETRY') AND pd.next_attempt_at<=clock_timestamp() AND (n.scope IN ('CHURCH','USER') OR u.role IN ('ADMIN','PASTOR') OR EXISTS(SELECT 1 FROM user_ministries um JOIN ministries m ON m.id=um.ministry_id AND m.status='ACTIVE' WHERE um.user_id=u.id AND um.ministry_id=n.ministry_id AND um.status='ACTIVE')) ORDER BY pd.id FOR UPDATE OF pd SKIP LOCKED LIMIT ?";
            $rows=$this->db->fetchAllAssociative($sql,[$limit],[ParameterType::INTEGER]);
            if(!$rows)return ['sent'=>0,'retried'=>0,'failed'=>0,'disabled'=>false];
            $messages=array_map(static fn($r)=>['to'=>$r['expo_push_token'],'sound'=>'default','title'=>'Nova atualização da comunidade','body'=>'Abra o aplicativo para ver a atualização.','data'=>['notification_id'=>(int)$r['notification_id']]],$rows);
            try{$tickets=$this->gateway->send($messages);}catch(\Throwable $e){foreach($rows as $r)$this->retry($r,'TRANSPORT');return ['sent'=>0,'retried'=>count($rows),'failed'=>0,'disabled'=>false];}
            $out=['sent'=>0,'retried'=>0,'failed'=>0,'disabled'=>false];
            foreach($rows as $i=>$r){$t=$tickets[$i]??['status'=>'error','details'=>['error'=>'InvalidResponse']]; if(($t['status']??null)==='ok'){$this->db->update('push_deliveries',['status'=>'SENT','attempts'=>(int)$r['attempts']+1,'ticket_id'=>$t['id']??null,'last_error_code'=>null,'updated_at'=>date(DATE_ATOM)],['id'=>$r['id']]);$out['sent']++;continue;} $code=(string)($t['details']['error']??'ExpoError'); if($code==='DeviceNotRegistered'){$this->db->update('push_devices',['status'=>'INVALID','updated_at'=>date(DATE_ATOM)],['id'=>$r['device_id']]);$this->fail($r,$code);$out['failed']++;}else{$this->retry($r,$code);$out['retried']++;}}
            return $out;
        });
    }
    private function retry(array $r,string $code):void{$attempt=(int)$r['attempts']+1;if($attempt>=5){$this->fail($r,$code);return;}$delay=min(3600,30*(2**($attempt-1)));$this->db->executeStatement("UPDATE push_deliveries SET status='RETRY',attempts=?,next_attempt_at=clock_timestamp()+(?||' seconds')::interval,last_error_code=?,updated_at=clock_timestamp() WHERE id=?",[$attempt,(string)$delay,$code,$r['id']]);}
    private function fail(array $r,string $code):void{$this->db->executeStatement("UPDATE push_deliveries SET status='FAILED',attempts=attempts+1,last_error_code=?,updated_at=clock_timestamp() WHERE id=?",[$code,$r['id']]);}
}
