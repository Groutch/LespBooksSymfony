<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Book;
use App\Entity\Loan;
use App\Form\LoanType;
use App\Repository\LoanRepository;
use App\Service\LoanAnonymizer;
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
    public function index(LoanRepository $loanRepository, LoanAnonymizer $loanAnonymizer): Response
    {
        // Avant de charger les retours : seul cet ecran les affiche, aucun nom
        // echu ne doit y apparaitre, meme si le tableau de bord n'a pas ete ouvert.
        $loanAnonymizer->anonymizeExpired();

        return $this->render('admin/loan/index.html.twig', [
            'activeLoans' => $loanRepository->findActive(),
            'returnedLoans' => $loanRepository->findReturned(20),
            'defaultLoanDays' => $this->defaultLoanDays,
        ]);
    }

    /**
     * Les liens « Prêter » de la fiche et de la liste des livres passent leur
     * adresse en `retour` : le prêt enregistré, on y revient, comme après Rendre
     * ou Prolonger. Le formulaire se soumet à sa propre adresse et le conserve.
     */
    #[Route('/livre/{id}/nouveau', name: 'admin_loan_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, Book $book): Response
    {
        $retour = $this->cheminDeRetour($request->query->getString('retour'));

        if (!$book->isAvailable()) {
            $this->addFlash('error', \sprintf('« %s » est déjà prêté à %s.', $book->getTitle(), $book->getActiveLoan()?->getBorrowerName()));

            return $this->redirect($retour ?? $this->generateUrl('admin_loan_index'));
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

            return $this->redirect($retour ?? $this->generateUrl('admin_loan_index'));
        }

        return $this->render('admin/loan/new.html.twig', [
            'form' => $form,
            'book' => $book,
            // « Annuler » ramène aussi d'où l'on vient, la fiche à défaut.
            'urlAnnulation' => $retour ?? $this->generateUrl('admin_book_show', ['id' => $book->getId()]),
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

            return $this->redirectBack($request);
        }

        $loan->setReturnedAt(new \DateTimeImmutable('today'));
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('« %s » est de retour en rayon.', $loan->getBook()?->getTitle()));

        return $this->redirectBack($request);
    }

    #[Route('/{id}/prolonger', name: 'admin_loan_extend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extend(Request $request, Loan $loan): Response
    {
        if (!$this->isCsrfTokenValid('prolonger-pret-'.$loan->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($loan->isReturned()) {
            $this->addFlash('error', 'Un prêt déjà rendu ne peut pas être prolongé.');

            return $this->redirectBack($request);
        }

        $dueAt = $this->readDueAt($request->request->getString('dueAt'), $loan);

        if (null === $dueAt) {
            $this->addFlash('error', 'La nouvelle échéance est invalide.');

            return $this->redirectBack($request);
        }

        if ($dueAt < $loan->getBorrowedAt()) {
            $this->addFlash('error', 'La nouvelle échéance ne peut pas précéder la date de prêt.');

            return $this->redirectBack($request);
        }

        $loan->setDueAt($dueAt);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Prêt prolongé jusqu\'au %s.', $dueAt->format('d/m/Y')));

        return $this->redirectBack($request);
    }

    /**
     * Rendre et Prolonger se font depuis la liste des prets, la fiche d'un livre
     * ou la liste des livres : on revient la ou l'on etait, filtres compris ; a
     * defaut, la liste des prets.
     */
    private function redirectBack(Request $request): Response
    {
        return $this->redirect(
            $this->cheminDeRetour($request->request->getString('retour'))
            ?? $this->generateUrl('admin_loan_index'),
        );
    }

    /**
     * Le chemin de retour vient du navigateur : seul un chemin interne a la
     * gestion est suivi, jamais une adresse exterieure.
     */
    private function cheminDeRetour(string $retour): ?string
    {
        return 1 === preg_match('#^/admin(?:[/?]|$)#', $retour) && !str_contains($retour, '\\')
            ? $retour
            : null;
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
