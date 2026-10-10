<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\Genre;
use App\Model\BookSearchCriteria;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Repository\GenreRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Une page de résultats : livres trouvés, et de quoi afficher le formulaire de
 * recherche partagé (catalog/_recherche.html.twig). Le catalogue public et la
 * liste de la gestion s'en servent tous deux, pour ne jamais diverger.
 */
class CatalogSearch
{
    public function __construct(
        private readonly BookRepository $bookRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly GenreRepository $genreRepository,
        private readonly AuthorRepository $authorRepository,
    ) {
    }

    /**
     * @param InputBag<string> $query
     *
     * @return array{
     *     books: Paginator<Book>,
     *     total: int,
     *     criteria: BookSearchCriteria,
     *     pageCount: int,
     *     categories: array<int, Category>,
     *     genres: array<int, Genre>,
     *     selectedAuthor: ?Author,
     * }
     */
    public function fromQuery(InputBag $query): array
    {
        $criteria = BookSearchCriteria::fromQuery($query);
        $books = $this->bookRepository->search($criteria);
        $total = \count($books);

        return [
            'books' => $books,
            'total' => $total,
            'criteria' => $criteria,
            'pageCount' => (int) ceil($total / BookSearchCriteria::PER_PAGE),
            'categories' => $this->categoryRepository->findAllOrdered(),
            'genres' => $this->genreRepository->findAllOrdered(),
            'selectedAuthor' => null === $criteria->author ? null : $this->authorRepository->findOneBySlug($criteria->author),
        ];
    }
}
