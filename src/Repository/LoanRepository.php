<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Loan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Loan>
 */
class LoanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Loan::class);
    }

    /**
     * @return array<int, Loan>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')->addSelect('b')
            ->andWhere('l.returnedAt IS NULL')
            ->orderBy('l.dueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, Loan>
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')->addSelect('b')
            ->andWhere('l.returnedAt IS NULL')
            ->andWhere('l.dueAt IS NOT NULL')
            ->andWhere('l.dueAt < :today')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('l.dueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.returnedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
