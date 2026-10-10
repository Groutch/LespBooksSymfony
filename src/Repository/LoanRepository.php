<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Loan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * Efface nom, prénom et note des prêts rendus au plus tard à `$limite`.
     *
     * Le prêt lui-même est conservé (livre et dates) : seul ce qui désigne une
     * personne disparaît. Les champs vidés valent '' et non NULL, ce qui évite
     * une migration ; le formulaire de prêt les rend obligatoires, donc une
     * chaîne vide ne peut signifier qu'un effacement (Loan::isAnonymized()).
     *
     * En DQL, comme deleteOrphans() : l'UnitOfWork n'en sait rien, il faut donc
     * l'appeler avant de charger les prêts à afficher.
     *
     * @return int nombre de prêts anonymisés
     */
    public function anonymizeReturnedUpTo(\DateTimeImmutable $limite): int
    {
        $qb = $this->createQueryBuilder('l');

        return (int) $qb->update()
            ->set('l.borrowerFirstName', ':vide')
            ->set('l.borrowerLastName', ':vide')
            ->set('l.notes', 'NULL')
            ->where('l.returnedAt IS NOT NULL')
            ->andWhere('l.returnedAt <= :limite')
            // Ne réécrit pas indéfiniment les mêmes lignes à chaque passage.
            ->andWhere($qb->expr()->orX(
                "l.borrowerFirstName <> ''",
                "l.borrowerLastName <> ''",
                'l.notes IS NOT NULL',
            ))
            ->setParameter('vide', '')
            ->setParameter('limite', $limite, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
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

    /**
     * @return array<int, Loan>
     */
    public function findReturned(int $limit): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')->addSelect('b')
            ->andWhere('l.returnedAt IS NOT NULL')
            ->orderBy('l.returnedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
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
