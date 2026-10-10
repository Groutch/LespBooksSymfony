<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Genre;
use App\Tests\AdminWebTestCase;

/**
 * La liste de la gestion partage la recherche du catalogue public : memes
 * filtres, memes parametres d'URL (BookSearchCriteria::fromQuery()).
 */
class AdminSearchTest extends AdminWebTestCase
{
    public function testTheBookListFiltersByShelfLikeThePublicCatalogue(): void
    {
        $policier = $this->createShelf('Policier');
        $roman = $this->createShelf('Roman');
        $this->createBook('Le Chien jaune', $policier, 'étagère 2');
        $this->createBook('Madame Bovary', $roman);

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/livres?genre=policier');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('details select[name="genre"]');
        self::assertSelectorTextContains('main ul', 'Le Chien jaune');
        self::assertSelectorTextNotContains('main ul', 'Madame Bovary');
        // Le rayon et la position s'affichent dans la liste : c'est ce qu'on y cherche.
        self::assertSelectorTextContains('main ul', 'Rayon Policier · étagère 2');
        // Retirer le filtre ramene a la liste de la gestion, pas au catalogue public.
        self::assertSelectorExists('a.chip-active[href="/admin/livres"]');
    }

    public function testEachShelfLeadsToItsBooks(): void
    {
        $policier = $this->createShelf('Policier');
        $this->createShelf('Poésie');
        $this->createBook('Le Chien jaune', $policier);

        $this->client->loginUser($this->createAdmin());
        // Book::setGenre() ne remplit pas Genre::$books : la requete doit relire
        // les rayons en base, comme en production, et non ceux restes en memoire.
        $this->entityManager->clear();
        $this->client->request('GET', '/admin/rayons');

        self::assertSelectorTextContains('a[href="/admin/livres?genre=policier"]', 'Voir le livre');
        self::assertSelectorTextContains('main', 'Aucun livre');
    }

    /** Le declencheur des filtres dit combien sont poses, sur les deux sites. */
    public function testTheRefineButtonCountsTheActiveFilters(): void
    {
        $this->createShelf('Policier');

        $this->client->request('GET', '/catalogue?genre=policier&disponibles=1');

        self::assertSelectorTextContains('details summary', 'Affiner la recherche');
        self::assertSelectorTextContains('details summary', '2 filtres actifs');
        self::assertSelectorExists('details[open]');
    }

    private function createShelf(string $name): Genre
    {
        $genre = (new Genre())->setName($name)->setSlug(strtolower(str_replace('é', 'e', $name)));
        $this->entityManager->persist($genre);
        $this->entityManager->flush();

        return $genre;
    }

    private function createBook(string $title, Genre $genre, ?string $shelfLocation = null): Book
    {
        $book = (new Book())->setTitle($title)->setGenre($genre)->setShelfLocation($shelfLocation);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }
}
