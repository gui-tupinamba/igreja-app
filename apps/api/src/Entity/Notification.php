<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable; use Doctrine\DBAL\Types\Types; use Doctrine\ORM\Mapping as ORM;
/** NotificationService owns writes. */
#[ORM\Entity,ORM\Table(name:'notifications')]
#[ORM\UniqueConstraint(name:'uniq_notification_idempotency',columns:['created_by_id','idempotency_key'])]
class Notification {
 #[ORM\Id,ORM\GeneratedValue(strategy:'IDENTITY'),ORM\Column(type:Types::INTEGER)] private ?int $id=null;
 #[ORM\ManyToOne(targetEntity:User::class),ORM\JoinColumn(name:'created_by_id',nullable:false,onDelete:'RESTRICT')] private User $createdBy;
 #[ORM\Column(length:20)] private string $scope;
 #[ORM\ManyToOne(targetEntity:Ministry::class),ORM\JoinColumn(name:'ministry_id',nullable:true,onDelete:'RESTRICT')] private ?Ministry $ministry;
 #[ORM\ManyToOne(targetEntity:User::class),ORM\JoinColumn(name:'target_user_id',nullable:true,onDelete:'RESTRICT')] private ?User $targetUser;
 #[ORM\Column(length:120)] private string $title;
 #[ORM\Column(type:Types::TEXT)] private string $body;
 #[ORM\Column(length:255,nullable:true)] private ?string $route;
 #[ORM\Column(name:'idempotency_key',length:64)] private string $idempotencyKey;
 #[ORM\Column(name:'payload_hash',length:64)] private string $payloadHash;
 #[ORM\Column(name:'created_at',type:Types::DATETIMETZ_IMMUTABLE)] private DateTimeImmutable $createdAt;
 private function __construct(){}
}
