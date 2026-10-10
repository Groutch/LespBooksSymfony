<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LoanRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Efface le nom des emprunteurs une fois passé le délai de conservation après
 * le retour du livre — délai annoncé aux habitants sur /confidentialite.
 *
 * Pas de tâche planifiée : l'hébergement mutualisé n'en exécute que si on la
 * configure à la main, et un oubli y garderait les noms indéfiniment sans que
 * personne s'en aperçoive. Le ménage se fait donc à l'ouverture du tableau de
 * bord, page d'accueil de l'application installée, et de la liste des prêts,
 * seul écran qui affiche des prêts rendus. Une requête, presque toujours sans
 * ligne à modifier.
 */
class LoanAnonymizer
{
    public function __construct(
        private readonly LoanRepository $loanRepository,
        #[Autowire('%app.borrower_retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    /**
     * @return int nombre de prêts anonymisés
     */
    public function anonymizeExpired(?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->loanRepository->anonymizeReturnedUpTo(
            $today->modify(\sprintf('-%d months', $this->retentionMonths)),
        );
    }
}
