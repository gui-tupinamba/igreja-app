<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserMinistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserMinistry> */
final class UserMinistryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMinistry::class);
    }
}
