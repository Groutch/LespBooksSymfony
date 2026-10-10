<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Genre;
use App\Tests\AdminWebTestCase;

/**
 * Navigation facon application : barre d'onglets au telephone, et bouton
 * retour vers la page parente sur chaque sous-page — installee sur iPhone,
 * l'application n'a aucun bouton retour du systeme.
 */
class NavigationTest extends AdminWebTestCase
{
    public function testEachSubPageLeadsBackToItsParent(): void
    {
        $book = (new Book())->setTitle('Le Horla');
        $rayon = (new Genre())->setName('Policier')->setSlug('policier');
        $this->entityManager->persist($book);
        $this->entityManager->persist($rayon);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        $fiche = '/admin/livres/'.$book->getId();

        $attendus = [
            $fiche => ['/admin/livres', 'Livres'],
            $fiche.'/modifier' => [$fiche, 'Le Horla'],
            '/admin/prets/livre/'.$book->getId().'/nouveau' => [$fiche, 'Le Horla'],
            '/admin/rayons/'.$rayon->getId().'/modifier' => ['/admin/rayons', 'Rayons'],
            '/admin/livres/nouveau?isbn=9782070360024' => ['/admin/livres/scanner', 'Scanner'],
            '/admin/livres/nouveau' => ['/admin/livres', 'Livres'],
        ];

        foreach ($attendus as $page => [$parent, $libelle]) {
            $this->client->request('GET', $page);

            self::assertResponseIsSuccessful($page);
            self::assertSelectorExists(\sprintf('header a[aria-label="Retour : %s"][href="%s"]', $libelle, $parent), $page);
        }
    }

    public function testTopLevelSectionsHaveNoBackButton(): void
    {
        $this->client->loginUser($this->createAdmin());

        foreach (['/admin', '/admin/livres', '/admin/prets', '/admin/rayons', '/admin/livres/scanner'] as $page) {
            $this->client->request('GET', $page);

            self::assertSelectorNotExists('header a[aria-label^="Retour"]', $page);
        }
    }

    /** La fiche et la modification d'un livre restent « dans » l'onglet Livres. */
    public function testTheTabBarHighlightsTheCurrentSection(): void
    {
        $book = (new Book())->setTitle('Le Horla');
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', '/admin/livres/'.$book->getId());
        self::assertSelectorTextContains('nav a[aria-current="page"][href="/admin/livres"]', 'Livres');

        $this->client->request('GET', '/admin/livres/nouveau');
        self::assertSelectorTextContains('nav a[aria-current="page"][href="/admin/livres/scanner"]', 'Scanner');
    }

    /** Au telephone, la deconnexion n'est plus dans la navigation : elle est sur l'accueil. */
    public function testTheDashboardOffersThePublicSiteAndTheLogout(): void
    {
        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('main a[href="/"]', 'Voir le site public');
        self::assertSelectorExists('main form[action="/deconnexion"]');
    }

    /** La fiche publique ramene a la recherche telle qu'on l'a laissee. */
    public function testThePublicBookPageLeadsBackToTheSameSearch(): void
    {
        $book = (new Book())->setTitle('Dune');
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $this->client->request('GET', '/livre/'.$book->getId(), server: [
            'HTTP_REFERER' => 'http://localhost/catalogue?q=dune&disponibles=1',
        ]);
        self::assertSelectorTextContains('main a[href="http://localhost/catalogue?q=dune&disponibles=1"]', 'Retour à la recherche');

        // Venu d'ailleurs : le catalogue entier, jamais une adresse exterieure.
        $this->client->request('GET', '/livre/'.$book->getId(), server: [
            'HTTP_REFERER' => 'https://exemple.invalid/catalogue',
        ]);
        self::assertSelectorTextContains('main a[href="/catalogue"]', 'Retour à la recherche');
    }
}
