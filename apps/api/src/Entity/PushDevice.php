<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
/** NotificationService owns writes. */
#[ORM\Entity, ORM\Table(name:'push_devices')]
#[ORM\UniqueConstraint(name:'uniq_push_device_token',columns:['expo_push_token'])]
#[ORM\Index(name:'idx_push_device_session',columns:['session_id','status'])]
class PushDevice {
 #[ORM\Id,ORM\GeneratedValue(strategy:'IDENTITY'),ORM\Column(type:Types::INTEGER)] private ?int $id=null;
 #[ORM\ManyToOne(targetEntity:User::class),ORM\JoinColumn(name:'user_id',nullable:false,onDelete:'CASCADE')] private User $user;
 #[ORM\ManyToOne(targetEntity:AuthSession::class),ORM\JoinColumn(name:'session_id',nullable:false,onDelete:'CASCADE')] private AuthSession $session;
 #[ORM\Column(name:'expo_push_token',length:255)] private string $expoPushToken;
 #[ORM\Column(length:10)] private string $platform;
 #[ORM\Column(length:10)] private string $status;
 #[ORM\Column(name:'last_seen_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $lastSeenAt;
 #[ORM\Column(name:'created_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt;
 #[ORM\Column(name:'updated_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $updatedAt;
 private function __construct(){}
}
