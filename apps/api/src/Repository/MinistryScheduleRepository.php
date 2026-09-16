<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MinistrySchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MinistrySchedule> */
final class MinistryScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MinistrySchedule::class);
    }
}
