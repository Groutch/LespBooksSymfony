<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Critères de recherche, alimentés par la query string. Le catalogue public et
 * la liste de la gestion lisent les mêmes paramètres : un lien filtré vaut pour
 * les deux, et le formulaire (catalog/_recherche.html.twig) est partagé.
 */
class BookSearchCriteria
{
    public const int PER_PAGE = 24;

    public const string SORT_RECENT = 'recent';
    public const string SORT_TITLE = 'title';
    public const string SORT_YEAR = 'year';

    public function __construct(
        public ?string $query = null,
        public ?string $category = null,
        public ?string $genre = null,
        public ?string $author = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public bool $availableOnly = false,
        public string $sort = self::SORT_RECENT,
        public int $page = 1,
    ) {
    }

    /**
     * Lecture tolérante de la query string : un champ vide ou aberrant vaut
     * « pas de filtre », jamais un filtre qui ne renverrait aucun résultat.
     *
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            query: $query->getString('q') ?: null,
            category: $query->getString('categorie') ?: null,
            genre: $query->getString('genre') ?: null,
            author: $query->getString('auteur') ?: null,
            yearFrom: self::readInt($query, 'anneeMin'),
            yearTo: self::readInt($query, 'anneeMax'),
            availableOnly: $query->getBoolean('disponibles'),
            sort: $query->getString('tri', self::SORT_RECENT),
            page: max(1, self::readInt($query, 'page') ?? 1),
        );
    }

    /**
     * InputBag::getInt() lève une exception sur une valeur vide ou non numérique,
     * ce qu'un formulaire de recherche produit constamment.
     *
     * @param InputBag<string> $query
     */
    private static function readInt(InputBag $query, string $key): ?int
    {
        $value = trim($query->getString($key));

        return ctype_digit($value) ? (int) $value : null;
    }

    public function getPage(): int
    {
        return max(1, $this->page);
    }

    public function getOffset(): int
    {
        return ($this->getPage() - 1) * self::PER_PAGE;
    }

    public function getSort(): string
    {
        return \in_array($this->sort, [self::SORT_RECENT, self::SORT_TITLE, self::SORT_YEAR], true)
            ? $this->sort
            : self::SORT_RECENT;
    }

    public function hasFilters(): bool
    {
        return null !== $this->query
            || null !== $this->category
            || null !== $this->genre
            || null !== $this->author
            || null !== $this->yearFrom
            || null !== $this->yearTo
            || $this->availableOnly;
    }
}
