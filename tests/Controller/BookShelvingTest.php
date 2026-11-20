<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Genre;
use App\Tests\AdminWebTestCase;

/**
 * « le ranger » ouvrait tout le formulaire de modification pour un seul champ :
 * une page courte ne demande que le rayon et la position sur l'etagere.
 */
class BookShelvingTest extends AdminWebTestCase
{
    public function testAShelflessBookIsShelvedFromItsOwnShortPage(): void
    {
        $policier = $this->createGenre('Policier');
        $this->createGenre('Roman');
        $book = $this->createBook('Le Mystère de la chambre jaune');

        $this->client->loginUser($this->createAdmin());
        $crawler = $this->client->request('GET', '/admin/livres/'.$book->getId());
        $crawler = $this->client->click($crawler->selectLink('le ranger')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ranger « Le Mystère de la chambre jaune »');
        self::assertSelectorCount(2, 'input[type="radio"][name="book_shelving[genre]"]');
        self::assertSelectorNotExists('[name="book_shelving[title]"]');

        $this->client->submit($crawler->selectButton('Ranger le livre')->form([
            'book_shelving[genre]' => (string) $policier->getId(),
            'book_shelving[shelfLocation]' => 'étagère 3',
        ]));

        self::assertResponseRedirects('/admin/livres/'.$book->getId());
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->find($book->getId());
        self::assertSame('Policier', $book?->getGenre()?->getName());
        self::assertSame('étagère 3', $book->getShelfLocation());
    }

    public function testAShelvedBookCanBeMovedFromItsSheet(): void
    {
        $roman = $this->createGenre('Roman');
        $book = $this->createBook('Germinal');
        $book->setGenre($roman);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        $crawler = $this->client->request('GET', '/admin/livres/'.$book->getId());
        $this->client->click($crawler->selectLink('Changer de place')->link());

        // Le rayon actuel est deja coche : on ne fait que le changer.
        self::assertSelectorExists('input[type="radio"][value="'.$roman->getId().'"][checked]');
    }

    public function testShelvingWithoutChoosingAShelfIsRefused(): void
    {
        $this->createGenre('Roman');
        $book = $this->createBook('Germinal');

        $this->client->loginUser($this->createAdmin());
        $crawler = $this->client->request('GET', '/admin/livres/'.$book->getId().'/ranger');
        $this->client->submit($crawler->selectButton('Ranger le livre')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choisissez le rayon');
    }

    public function testWithoutAnyShelfThePageLeadsToTheShelfScreen(): void
    {
        $book = $this->createBook('Germinal');

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/livres/'.$book->getId().'/ranger');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('main form[name="book_shelving"]');
        self::assertSelectorExists('main a[href="/admin/rayons"]');
    }

    private function createGenre(string $name): Genre
    {
        $genre = (new Genre())->setName($name)->setSlug(strtolower($name));
        $this->entityManager->persist($genre);
        $this->entityManager->flush();

        return $genre;
    }

    private function createBook(string $title): Book
    {
        $book = (new Book())->setTitle($title);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }
}
