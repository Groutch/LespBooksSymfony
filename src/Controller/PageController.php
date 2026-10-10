<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages d'information du site public. Rien n'y est calcule : adresse, horaires
 * et mentions viennent du parametre `app.bibliotheque`, expose aux gabarits.
 */
class PageController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.default_loan_days%')]
        private readonly int $defaultLoanDays,
        #[Autowire('%app.borrower_retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    #[Route('/infos-pratiques', name: 'practical_info', methods: ['GET'])]
    public function practicalInfo(): Response
    {
        // La duree annoncee aux habitants est celle que la gestion propose au
        // moment du pret : elles ne peuvent pas diverger.
        return $this->render('pages/infos_pratiques.html.twig', [
            'defaultLoanDays' => $this->defaultLoanDays,
        ]);
    }

    #[Route('/mentions-legales', name: 'legal_notice', methods: ['GET'])]
    public function legalNotice(): Response
    {
        return $this->render('pages/mentions_legales.html.twig');
    }

    #[Route('/confidentialite', name: 'privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        // Meme parametre que LoanAnonymizer : la promesse et l'effacement reel
        // ne peuvent pas diverger.
        return $this->render('pages/confidentialite.html.twig', [
            'retentionMonths' => $this->retentionMonths,
        ]);
    }
}
