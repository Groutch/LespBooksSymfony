<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return array<int, Category>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Catégories triées par nom, avec le nombre de livres, pour l'écran d'administration.
     *
     * @return array<int, array{category: Category, bookCount: int}>
     */
    public function findAllWithBookCount(): array
    {
        /** @var array<int, array{0: Category, bookCount: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c AS category', 'COUNT(b.id) AS bookCount')
            ->leftJoin('c.books', 'b')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'category' => $row['category'],
                'bookCount' => (int) $row['bookCount'],
            ],
            $rows,
        );
    }
}
