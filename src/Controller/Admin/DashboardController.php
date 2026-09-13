<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\BookRepository;
use App\Repository\LoanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        BookRepository $bookRepository,
        LoanRepository $loanRepository,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'bookCount' => $bookRepository->countAll(),
            'activeLoanCount' => $loanRepository->countActive(),
            'overdueLoans' => $loanRepository->findOverdue(),
            'latestBooks' => $bookRepository->findLatest(5),
        ]);
    }
}
