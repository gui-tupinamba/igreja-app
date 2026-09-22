<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
/** PushDeliveryService owns writes. */
#[ORM\Entity,ORM\Table(name:'push_deliveries')]
#[ORM\UniqueConstraint(name:'uniq_push_delivery',columns:['notification_id','device_id'])]
#[ORM\Index(name:'idx_push_delivery_queue',columns:['next_attempt_at','id'])]
class PushDelivery {
 #[ORM\Id,ORM\GeneratedValue(strategy:'IDENTITY'),ORM\Column(type:Types::INTEGER)] private ?int $id=null;
 #[ORM\ManyToOne(targetEntity:Notification::class),ORM\JoinColumn(name:'notification_id',nullable:false,onDelete:'CASCADE')] private Notification $notification;
 #[ORM\ManyToOne(targetEntity:PushDevice::class),ORM\JoinColumn(name:'device_id',nullable:false,onDelete:'CASCADE')] private PushDevice $device;
 #[ORM\Column(length:12)] private string $status;
 #[ORM\Column(type:Types::SMALLINT,options:['default'=>0])] private int $attempts;
 #[ORM\Column(name:'next_attempt_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $nextAttemptAt;
 #[ORM\Column(name:'ticket_id',length:255,nullable:true)] private ?string $ticketId;
 #[ORM\Column(name:'last_error_code',length:80,nullable:true)] private ?string $lastErrorCode;
 #[ORM\Column(name:'created_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt;
 #[ORM\Column(name:'updated_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $updatedAt;
 private function __construct(){}
}
