<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Book;
use App\Entity\Loan;
use App\Form\LoanType;
use App\Repository\LoanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/prets')]
#[IsGranted('ROLE_ADMIN')]
class LoanController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.default_loan_days%')]
        private readonly int $defaultLoanDays,
    ) {
    }

    #[Route('', name: 'admin_loan_index', methods: ['GET'])]
    public function index(LoanRepository $loanRepository): Response
    {
        return $this->render('admin/loan/index.html.twig', [
            'activeLoans' => $loanRepository->findActive(),
            'returnedLoans' => $loanRepository->findReturned(20),
            'defaultLoanDays' => $this->defaultLoanDays,
        ]);
    }

    #[Route('/livre/{id}/nouveau', name: 'admin_loan_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, Book $book): Response
    {
        if (!$book->isAvailable()) {
            $this->addFlash('error', \sprintf('« %s » est déjà prêté à %s.', $book->getTitle(), $book->getActiveLoan()?->getBorrowerName()));

            return $this->redirectToRoute('admin_loan_index');
        }

        $loan = new Loan();
        $loan->setBook($book);
        $loan->setDueAt(new \DateTimeImmutable(\sprintf('today +%d days', $this->defaultLoanDays)));

        $form = $this->createForm(LoanType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($loan);
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('« %s » est prêté à %s.', $book->getTitle(), $loan->getBorrowerName()));

            return $this->redirectToRoute('admin_loan_index');
        }

        return $this->render('admin/loan/new.html.twig', [
            'form' => $form,
            'book' => $book,
        ]);
    }

    #[Route('/{id}/rendre', name: 'admin_loan_return', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markReturned(Request $request, Loan $loan): Response
    {
        if (!$this->isCsrfTokenValid('rendre-pret-'.$loan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($loan->isReturned()) {
            $this->addFlash('error', 'Ce prêt a déjà été rendu.');

            return $this->redirectToRoute('admin_loan_index');
        }

        $loan->setReturnedAt(new \DateTimeImmutable('today'));
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('« %s » est de retour en rayon.', $loan->getBook()?->getTitle()));

        return $this->redirectToRoute('admin_loan_index');
    }

    #[Route('/{id}/prolonger', name: 'admin_loan_extend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extend(Request $request, Loan $loan): Response
    {
        if (!$this->isCsrfTokenValid('prolonger-pret-'.$loan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($loan->isReturned()) {
            $this->addFlash('error', 'Un prêt déjà rendu ne peut pas être prolongé.');

            return $this->redirectToRoute('admin_loan_index');
        }

        $dueAt = $this->readDueAt($request->request->getString('dueAt'), $loan);

        if (null === $dueAt) {
            $this->addFlash('error', 'La nouvelle échéance est invalide.');

            return $this->redirectToRoute('admin_loan_index');
        }

        if ($dueAt < $loan->getBorrowedAt()) {
            $this->addFlash('error', 'La nouvelle échéance ne peut pas précéder la date de prêt.');

            return $this->redirectToRoute('admin_loan_index');
        }

        $loan->setDueAt($dueAt);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Prêt prolongé jusqu\'au %s.', $dueAt->format('d/m/Y')));

        return $this->redirectToRoute('admin_loan_index');
    }

    /**
     * Sans date saisie, on repousse l'échéance de la durée de prêt standard.
     */
    private function readDueAt(string $submitted, Loan $loan): ?\DateTimeImmutable
    {
        if ('' === $submitted) {
            $from = $loan->getDueAt() ?? new \DateTimeImmutable('today');

            return $from->modify(\sprintf('+%d days', $this->defaultLoanDays));
        }

        $dueAt = \DateTimeImmutable::createFromFormat('!Y-m-d', $submitted);

        return false === $dueAt ? null : $dueAt;
    }
}
