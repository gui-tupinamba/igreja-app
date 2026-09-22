<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
/** NotificationService owns writes. */
#[ORM\Entity,ORM\Table(name:'notification_recipients')]
#[ORM\Index(name:'idx_notification_recipient_user',columns:['user_id','notification_id'])]
class NotificationRecipient {
 #[ORM\Id,ORM\ManyToOne(targetEntity:Notification::class),ORM\JoinColumn(name:'notification_id',nullable:false,onDelete:'CASCADE')] private Notification $notification;
 #[ORM\Id,ORM\ManyToOne(targetEntity:User::class),ORM\JoinColumn(name:'user_id',nullable:false,onDelete:'CASCADE')] private User $user;
 #[ORM\Column(name:'read_at',type:Types::DATETIMETZ_IMMUTABLE,nullable:true)] private ?DateTimeImmutable $readAt;
 #[ORM\Column(name:'created_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt;
 private function __construct(){}
}
