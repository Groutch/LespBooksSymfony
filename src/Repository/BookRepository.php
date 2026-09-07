<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use App\Model\BookSearchCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    public function findOneByIsbn(string $isbn): ?Book
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.isbn13 = :isbn OR b.isbn10 = :isbn')
            ->setParameter('isbn', $isbn)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Paginator<Book>
     */
    public function search(BookSearchCriteria $criteria): Paginator
    {
        $qb = $this->createSearchQueryBuilder($criteria)
            ->setFirstResult($criteria->getOffset())
            ->setMaxResults(BookSearchCriteria::PER_PAGE);

        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }

    /**
     * @return array<int, Book>
     */
    public function findLatest(int $limit = 12): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.authors', 'a')->addSelect('a')
            ->leftJoin('b.images', 'i')->addSelect('i')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createSearchQueryBuilder(BookSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.authors', 'a')->addSelect('a')
            ->leftJoin('b.categories', 'c')->addSelect('c')
            ->leftJoin('b.genre', 'g')->addSelect('g')
            ->leftJoin('b.images', 'i')->addSelect('i');

        if (null !== $criteria->query && '' !== trim($criteria->query)) {
            $qb->andWhere($qb->expr()->orX(
                'b.title LIKE :q',
                'b.subtitle LIKE :q',
                'b.description LIKE :q',
                'b.publisher LIKE :q',
                'b.isbn13 LIKE :q',
                'b.isbn10 LIKE :q',
                'a.name LIKE :q',
            ))->setParameter('q', '%'.trim($criteria->query).'%');
        }

        if (null !== $criteria->category) {
            $qb->andWhere('c.slug = :categorySlug')->setParameter('categorySlug', $criteria->category);
        }

        if (null !== $criteria->genre) {
            $qb->andWhere('g.slug = :genreSlug')->setParameter('genreSlug', $criteria->genre);
        }

        if (null !== $criteria->author) {
            $qb->andWhere('a.slug = :authorSlug')->setParameter('authorSlug', $criteria->author);
        }

        if (null !== $criteria->yearFrom) {
            $qb->andWhere('b.publishedYear >= :yearFrom')->setParameter('yearFrom', $criteria->yearFrom);
        }

        if (null !== $criteria->yearTo) {
            $qb->andWhere('b.publishedYear <= :yearTo')->setParameter('yearTo', $criteria->yearTo);
        }

        if ($criteria->availableOnly) {
            $qb->leftJoin('b.loans', 'activeLoan', Join::WITH, 'activeLoan.returnedAt IS NULL')
                ->andWhere('activeLoan.id IS NULL');
        }

        return match ($criteria->getSort()) {
            BookSearchCriteria::SORT_TITLE => $qb->orderBy('b.title', 'ASC'),
            BookSearchCriteria::SORT_YEAR => $qb->orderBy('b.publishedYear', 'DESC')->addOrderBy('b.title', 'ASC'),
            default => $qb->orderBy('b.createdAt', 'DESC'),
        };
    }
}
