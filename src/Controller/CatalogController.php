<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Model\BookSearchCriteria;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Repository\GenreRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consultation publique du catalogue : aucune authentification requise.
 */
class CatalogController extends AbstractController
{
    #[Route('/catalogue', name: 'catalog_index', methods: ['GET'])]
    public function index(
        Request $request,
        BookRepository $bookRepository,
        CategoryRepository $categoryRepository,
        GenreRepository $genreRepository,
        AuthorRepository $authorRepository,
    ): Response {
        $criteria = $this->criteriaFromRequest($request);
        $paginator = $bookRepository->search($criteria);
        $total = \count($paginator);

        return $this->render('catalog/index.html.twig', [
            'books' => $paginator,
            'total' => $total,
            'criteria' => $criteria,
            'pageCount' => (int) ceil($total / BookSearchCriteria::PER_PAGE),
            'categories' => $categoryRepository->findAllOrdered(),
            'genres' => $genreRepository->findAllOrdered(),
            'selectedAuthor' => null === $criteria->author ? null : $authorRepository->findOneBySlug($criteria->author),
        ]);
    }

    #[Route('/livre/{id}', name: 'catalog_book', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function book(Book $book): Response
    {
        return $this->render('catalog/show.html.twig', [
            'book' => $book,
        ]);
    }

    /**
     * Lecture tolérante de la query string : un champ vide ou aberrant vaut
     * « pas de filtre », jamais un filtre qui ne renverrait aucun résultat.
     */
    private function criteriaFromRequest(Request $request): BookSearchCriteria
    {
        $query = $request->query;

        return new BookSearchCriteria(
            query: $query->getString('q') ?: null,
            category: $query->getString('categorie') ?: null,
            genre: $query->getString('genre') ?: null,
            author: $query->getString('auteur') ?: null,
            yearFrom: $this->readInt($query, 'anneeMin'),
            yearTo: $this->readInt($query, 'anneeMax'),
            availableOnly: $query->getBoolean('disponibles'),
            sort: $query->getString('tri', BookSearchCriteria::SORT_RECENT),
            page: max(1, $this->readInt($query, 'page') ?? 1),
        );
    }

    /**
     * InputBag::getInt() lève une exception sur une valeur vide ou non numérique,
     * ce qu'un formulaire de recherche produit constamment.
     */
    private function readInt(InputBag $query, string $key): ?int
    {
        $value = trim($query->getString($key));

        return ctype_digit($value) ? (int) $value : null;
    }
}
