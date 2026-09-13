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
     * Supprime les catégories qui ne sont plus rattachées à aucun livre.
     *
     * Une catégorie n'est qu'un mot-clé : sans livre pour la porter, elle n'a plus
     * d'existence propre. Sans ce ménage, retirer un thème du dernier livre qui le
     * portait laisserait une étiquette fantôme dans les filtres du catalogue public.
     *
     * Volontairement en DQL : passer par l'`EntityManager` obligerait à charger
     * toutes les catégories pour n'en supprimer souvent aucune. En contrepartie,
     * l'UnitOfWork n'en sait rien — appeler après le flush, jamais avant.
     *
     * @return int nombre de catégories supprimées
     */
    public function deleteOrphans(): int
    {
        // Deux requêtes plutôt qu'une : MySQL refuse qu'un DELETE lise sa propre
        // table dans une sous-requête (erreur 1093). Le volume est de toute façon
        // celui d'une bibliothèque de village, pas d'un catalogue national.
        /** @var array<int, int> $orphelines */
        $orphelines = $this->createQueryBuilder('c')
            ->select('c.id')
            ->leftJoin('c.books', 'livre')
            ->groupBy('c.id')
            ->having('COUNT(livre.id) = 0')
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $orphelines) {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $orphelines)
            ->getQuery()
            ->execute();
    }

    /**
     * Catégories triées par nom, avec le nombre de livres.
     *
     * Sert la page d'accueil publique, qui affiche le compte à côté de chaque thème.
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
