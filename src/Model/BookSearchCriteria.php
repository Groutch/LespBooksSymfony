<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Critères de la recherche avancée publique, alimentés par la query string.
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
