<?php

declare(strict_types=1);

namespace App\Notification;

final class ExpoPushGateway
{
    public function enabled(): bool { return filter_var(getenv('EXPO_PUSH_ENABLED') ?: '0', FILTER_VALIDATE_BOOL); }

    /** @return array<int, array{status:string,id?:string,details?:array}> */
    public function send(array $messages): array
    {
        $headers="Content-Type: application/json\r\nAccept: application/json\r\n";
        if (($token=getenv('EXPO_PUSH_ACCESS_TOKEN'))!==false && $token!=='') $headers.='Authorization: Bearer '.$token."\r\n";
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($messages,JSON_THROW_ON_ERROR),'timeout'=>15,'ignore_errors'=>true]]);
        $raw=@file_get_contents('https://exp.host/--/api/v2/push/send',false,$context);
        if($raw===false) throw new \RuntimeException('expo_unavailable');
        $status=(int)preg_replace('/^HTTP\/\S+\s+(\d+).*$/','$1',$http_response_header[0]??'0');
        if($status===429||$status>=500) throw new \RuntimeException('expo_retryable_'.$status);
        if($status<200||$status>=300) throw new \RuntimeException('expo_http_'.$status);
        $decoded=json_decode($raw,true,16,JSON_THROW_ON_ERROR); $data=$decoded['data']??null;
        if(!is_array($data)) throw new \RuntimeException('expo_invalid_response');
        return isset($data['status'])?[$data]:$data;
    }
}
