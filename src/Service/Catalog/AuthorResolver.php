<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Author;
use App\Repository\AuthorRepository;
use App\Service\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Même logique de déduplication que pour les catégories, appliquée aux auteurs
 * ("J.R.R. Tolkien" et "J. R. R. Tolkien" doivent rester une seule fiche).
 */
class AuthorResolver
{
    /** @var array<string, Author> */
    private array $pending = [];

    public function __construct(
        private readonly AuthorRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TextNormalizer $normalizer,
    ) {
    }

    public function resolve(string $rawName): ?Author
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

        $author = (new Author())
            ->setName($name)
            ->setSlug($slug);

        $this->entityManager->persist($author);
        $this->pending[$slug] = $author;

        return $author;
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<int, Author>
     */
    public function resolveAll(array $names): array
    {
        $authors = [];

        foreach ($names as $name) {
            $author = $this->resolve($name);

            if (null !== $author && !\in_array($author, $authors, true)) {
                $authors[] = $author;
            }
        }

        return $authors;
    }
}
