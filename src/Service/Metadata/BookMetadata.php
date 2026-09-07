<?php

declare(strict_types=1);

namespace App\Service\Metadata;

/**
 * Métadonnées d'un livre renvoyées par une source externe.
 *
 * Objet immuable : la fusion multi-sources produit une nouvelle instance.
 */
final class BookMetadata
{
    /**
     * @param array<int, string> $authors
     * @param array<int, string> $categories
     * @param array<int, string> $sources
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $subtitle = null,
        public readonly array $authors = [],
        public readonly ?string $publisher = null,
        public readonly ?int $publishedYear = null,
        public readonly ?int $pageCount = null,
        public readonly ?string $language = null,
        public readonly ?string $description = null,
        public readonly array $categories = [],
        public readonly ?string $coverUrl = null,
        public readonly ?string $isbn13 = null,
        public readonly ?string $isbn10 = null,
        public readonly array $sources = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->title && [] === $this->authors;
    }

    /**
     * Complète les champs manquants avec ceux d'une source moins prioritaire.
     *
     * Les catégories ne sont PAS fusionnées : la source la plus fiable impose sa
     * liste. Fusionner produirait des dizaines de catégories parasites, alors
     * qu'elles servent ici à ranger physiquement les livres.
     */
    public function mergeFallback(self $other): self
    {
        return new self(
            title: $this->title ?? $other->title,
            subtitle: $this->subtitle ?? $other->subtitle,
            authors: [] !== $this->authors ? $this->authors : $other->authors,
            publisher: $this->publisher ?? $other->publisher,
            publishedYear: $this->publishedYear ?? $other->publishedYear,
            pageCount: $this->pageCount ?? $other->pageCount,
            language: $this->language ?? $other->language,
            description: $this->description ?? $other->description,
            categories: [] !== $this->categories ? $this->categories : $other->categories,
            coverUrl: $this->coverUrl ?? $other->coverUrl,
            isbn13: $this->isbn13 ?? $other->isbn13,
            isbn10: $this->isbn10 ?? $other->isbn10,
            sources: array_values(array_unique([...$this->sources, ...$other->sources])),
        );
    }
}
