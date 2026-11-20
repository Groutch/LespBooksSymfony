<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\Genre;
use App\Entity\Loan;
use App\Model\BookSearchCriteria;
use App\Repository\BookRepository;
use App\Tests\AdminWebTestCase;

class CatalogTest extends AdminWebTestCase
{
    public function testCatalogIsReachableWithoutLoggingIn(): void
    {
        $this->createBook('Dune');

        $this->client->request('GET', '/catalogue');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dune');
    }

    public function testSearchFiltersOnTitleAndAuthorFields(): void
    {
        $this->createBook('Dune');
        $this->createBook('Germinal');

        $crawler = $this->client->request('GET', '/catalogue?q=Germ');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('article')->count());
        self::assertSelectorTextContains('article', 'Germinal');
    }

    /**
     * Filtrer sur un auteur ne doit pas filtrer les auteurs affiches : la jointure
     * qui sert au filtre ne peut pas etre celle qui charge la collection, sinon le
     * livre ne ramene que l'auteur cherche.
     */
    public function testFilteringByOneAuthorKeepsTheCoAuthorsOnTheCard(): void
    {
        $this->createCoWrittenBook();

        $crawler = $this->client->request('GET', '/catalogue?auteur=neil-gaiman');

        self::assertSame(1, $crawler->filter('article')->count());
        self::assertSelectorTextContains('article', 'Terry Pratchett');
        self::assertSelectorTextContains('article', 'Neil Gaiman');
    }

    public function testSearchingForOneAuthorNameKeepsTheCoAuthorsOnTheCard(): void
    {
        $this->createCoWrittenBook();

        $crawler = $this->client->request('GET', '/catalogue?q=Gaiman');

        self::assertSame(1, $crawler->filter('article')->count());
        self::assertSelectorTextContains('article', 'Terry Pratchett');
    }

    public function testFilteringByOneCategoryStillLoadsAllTheBookCategories(): void
    {
        $fantasy = (new Category())->setName('Fantasy')->setSlug('fantasy');
        $humour = (new Category())->setName('Humour')->setSlug('humour');
        $this->entityManager->persist($fantasy);
        $this->entityManager->persist($humour);
        $this->createBook('De bons présages')->addCategory($fantasy)->addCategory($humour);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $books = iterator_to_array(static::getContainer()->get(BookRepository::class)
            ->search(new BookSearchCriteria(category: 'fantasy')));

        self::assertCount(1, $books);
        self::assertCount(2, $books[0]->getCategories());
    }

    public function testEmptyFiltersDoNotHideTheWholeCatalogue(): void
    {
        $this->createBook('Dune');

        // Un formulaire soumis a vide envoie des chaines vides : elles ne doivent
        // pas etre interpretees comme un filtre.
        $crawler = $this->client->request('GET', '/catalogue?q=&categorie=&genre=&anneeMin=&anneeMax=');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('article')->count());
    }

    public function testCategoryFilterUsesTheSlug(): void
    {
        $category = (new Category())->setName('Policier')->setSlug('policier');
        $this->entityManager->persist($category);

        $book = $this->createBook('Le Mystère de la chambre jaune');
        $book->addCategory($category);
        $this->createBook('Dune');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/catalogue?categorie=policier');

        self::assertSame(1, $crawler->filter('article')->count());
        self::assertSelectorTextContains('article', 'Le Mystère de la chambre jaune');
    }

    public function testAvailableOnlyExcludesBorrowedBooks(): void
    {
        $borrowed = $this->createBook('Fondation');
        $this->createBook('Dune');

        $loan = (new Loan())
            ->setBook($borrowed)
            ->setBorrowerFirstName('Marie')
            ->setBorrowerLastName('Dupont');
        $borrowed->addLoan($loan);
        $this->entityManager->persist($loan);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/catalogue?disponibles=1');

        self::assertSame(1, $crawler->filter('article')->count());
        self::assertSelectorTextContains('article', 'Dune');
    }

    public function testBookPageNeverRevealsTheBorrowerIdentity(): void
    {
        $book = $this->createBook('Fondation');

        $loan = (new Loan())
            ->setBook($book)
            ->setBorrowerFirstName('Marie')
            ->setBorrowerLastName('Dupont');
        $book->addLoan($loan);
        $this->entityManager->persist($loan);
        $this->entityManager->flush();

        $this->client->request('GET', '/livre/'.$book->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Emprunté');
        self::assertStringNotContainsString('Dupont', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le rayon est porte par `Genre`, la position sur l'etagere par
     * `Book::shelfLocation`. La fiche publique affichait autrefois le second sous
     * le mot « rayon » pendant que le premier figurait ailleurs sur la meme page.
     */
    public function testBookPageShowsTheGenreAsTheShelfAndTheLocationBeside(): void
    {
        $rayon = (new Genre())->setName('Littérature')->setSlug('litterature');

        $book = (new Book())->setTitle('Germinal');
        $book->setGenre($rayon);
        $book->setShelfLocation('étagère 3');

        $this->entityManager->persist($rayon);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $this->client->request('GET', '/livre/'.$book->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Où le trouver');
        // Le rayon reste cliquable : c'est un filtre du catalogue.
        self::assertSelectorTextContains('a[href="/catalogue?genre=litterature"]', 'Littérature');
        self::assertSelectorTextContains('body', 'étagère 3');
    }

    public function testUnknownBookReturnsNotFound(): void
    {
        $this->client->request('GET', '/livre/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function createBook(string $title): Book
    {
        $book = (new Book())->setTitle($title);

        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    /**
     * L'EntityManager est vide ensuite : le livre deja en memoire, avec ses deux
     * auteurs, masquerait sinon une collection mal relue par la recherche.
     */
    private function createCoWrittenBook(): void
    {
        $book = $this->createBook('De bons présages');

        foreach (['Terry Pratchett' => 'terry-pratchett', 'Neil Gaiman' => 'neil-gaiman'] as $name => $slug) {
            $author = (new Author())->setName($name)->setSlug($slug);
            $this->entityManager->persist($author);
            $book->addAuthor($author);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
