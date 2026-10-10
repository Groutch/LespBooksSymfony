<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Service\Catalog\CatalogSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consultation publique du catalogue : aucune authentification requise.
 */
class CatalogController extends AbstractController
{
    #[Route('/catalogue', name: 'catalog_index', methods: ['GET'])]
    public function index(Request $request, CatalogSearch $catalogSearch): Response
    {
        return $this->render('catalog/index.html.twig', $catalogSearch->fromQuery($request->query));
    }

    #[Route('/livre/{id}', name: 'catalog_book', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function book(Book $book): Response
    {
        return $this->render('catalog/show.html.twig', [
            'book' => $book,
        ]);
    }
}
