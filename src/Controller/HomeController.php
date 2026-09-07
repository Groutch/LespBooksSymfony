<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(BookRepository $bookRepository, CategoryRepository $categoryRepository): Response
    {
        return $this->render('home/index.html.twig', [
            'latestBooks' => $bookRepository->findLatest(12),
            'categories' => $categoryRepository->findAllWithBookCount(),
            'bookCount' => $bookRepository->countAll(),
        ]);
    }
}
