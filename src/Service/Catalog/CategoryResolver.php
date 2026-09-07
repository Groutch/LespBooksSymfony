<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Service\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Trouve ou crée une catégorie sans jamais en dupliquer une existante.
 *
 * Objectif métier : les catégories servent au rangement physique des livres,
 * il ne doit donc pas exister à la fois "Science-fiction" et "science fiction".
 */
class CategoryResolver
{
    /**
     * Catégories créées pendant la requête courante mais pas encore flushées,
     * indexées par slug, pour ne pas les recréer deux fois dans le même import.
     *
     * @var array<string, Category>
     */
    private array $pending = [];

    public function __construct(
        private readonly CategoryRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TextNormalizer $normalizer,
        #[Autowire('%app.category_similarity_threshold%')]
        private readonly float $similarityThreshold = 0.85,
    ) {
    }

    /**
     * Renvoie la catégorie correspondante, en la créant seulement si aucune
     * catégorie suffisamment proche n'existe déjà.
     */
    public function resolve(string $rawName): ?Category
    {
        $name = $this->normalizer->cleanLabel($rawName);

        if (\strlen($name) < 2) {
            return null;
        }

        $slug = $this->normalizer->slugify($name);

        if ('' === $slug) {
            return null;
        }

        if (isset($this->pending[$slug])) {
            return $this->pending[$slug];
        }

        $existing = $this->repository->findOneBySlug($slug);
        if (null !== $existing) {
            return $existing;
        }

        $similar = $this->findSimilar($name);
        if (null !== $similar) {
            return $similar;
        }

        $category = (new Category())
            ->setName($name)
            ->setSlug($slug);

        $this->entityManager->persist($category);
        $this->pending[$slug] = $category;

        return $category;
    }

    /**
     * Catégorie existante jugée équivalente, au-dessus du seuil de similarité.
     */
    public function findSimilar(string $name): ?Category
    {
        $best = null;
        $bestScore = $this->similarityThreshold;

        foreach ([...$this->repository->findAllOrdered(), ...array_values($this->pending)] as $candidate) {
            $score = $this->normalizer->similarity($name, $candidate->getName());

            if ($score >= $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Suggestions classées, utilisées par l'écran d'administration pour proposer
     * un rapprochement avant de créer une catégorie.
     *
     * @return array<int, array{category: Category, score: float}>
     */
    public function suggest(string $name, int $limit = 5): array
    {
        $scored = [];

        foreach ($this->repository->findAllOrdered() as $candidate) {
            $score = $this->normalizer->similarity($name, $candidate->getName());
            if ($score > 0.5) {
                $scored[] = ['category' => $candidate, 'score' => $score];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return \array_slice($scored, 0, $limit);
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<int, Category>
     */
    public function resolveAll(array $names): array
    {
        $categories = [];

        foreach ($names as $name) {
            $category = $this->resolve($name);

            if (null !== $category && !\in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }
}
