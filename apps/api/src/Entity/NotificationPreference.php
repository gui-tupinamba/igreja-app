<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
/** NotificationService owns writes. */
#[ORM\Entity, ORM\Table(name:'notification_preferences')]
class NotificationPreference {
 #[ORM\Id, ORM\OneToOne(targetEntity:User::class), ORM\JoinColumn(name:'user_id',nullable:false,onDelete:'CASCADE')] private User $user;
 #[ORM\Column(name:'push_enabled',options:['default'=>true])] private bool $pushEnabled;
 #[ORM\Column(name:'church_push_enabled',options:['default'=>true])] private bool $churchPushEnabled;
 #[ORM\Column(name:'ministry_push_enabled',options:['default'=>true])] private bool $ministryPushEnabled;
 #[ORM\Column(name:'updated_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $updatedAt;
 private function __construct(){}
}
