<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Category;
use App\Tests\AdminWebTestCase;

class BookAdminTest extends AdminWebTestCase
{
    public function testAdminAreaRejectsAnonymousVisitors(): void
    {
        $this->client->request('GET', '/admin/livres');

        self::assertResponseRedirects('/connexion');
    }

    public function testPublicHomePageStaysAccessible(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    /**
     * Le scanner ne doit interroger que la base : c'est ce qui evite de lancer
     * MetadataFetcher deux fois par livre, une fois pour rien.
     */
    public function testIsbnCheckReportsAnUnknownIsbnWithoutQueryingExternalSources(): void
    {
        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', '/admin/livres/verifier-isbn/9782070360024');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('9782070360024', $data['isbn13']);
        self::assertNull($data['existing']);
        // Une reponse allegee : aucune cle « book », donc aucune source externe.
        self::assertArrayNotHasKey('book', $data);
    }

    public function testIsbnCheckPointsToTheExistingBook(): void
    {
        $this->client->loginUser($this->createAdmin());

        $book = (new Book())->setTitle('Germinal')->setIsbn13('9782070360024');
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        // L'ISBN tirete doit etre reconnu : c'est la forme imprimee sur les livres.
        $this->client->request('GET', '/admin/livres/verifier-isbn/978-2-07-036002-4');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('Germinal', $data['existing']['title']);
        self::assertSame('/admin/livres/'.$book->getId().'/modifier', $data['existing']['url']);
    }

    public function testIsbnCheckRejectsAnInvalidCode(): void
    {
        $this->client->loginUser($this->createAdmin());

        $this->client->request('GET', '/admin/livres/verifier-isbn/1234567890123');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanCreateBookWithFreeTextAuthorsAndCategories(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Le Mystère de la chambre jaune',
            'book[authorsText]' => 'Gaston Leroux',
            'book[categoriesText]' => 'Policier, Roman',
            'book[isbn13]' => '9782253004226',
        ]));

        self::assertResponseRedirects('/admin/livres');

        $book = $this->entityManager->getRepository(Book::class)->findOneBy(['title' => 'Le Mystère de la chambre jaune']);

        self::assertNotNull($book);
        self::assertSame('Gaston Leroux', $book->getAuthorNames());
        self::assertCount(2, $book->getCategories());
    }

    public function testSimilarCategoryIsReusedInsteadOfDuplicated(): void
    {
        $existing = (new Category())->setName('Science-fiction')->setSlug('science-fiction');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Dune',
            // Variante de casse et d'accent : doit etre rattachee a la categorie existante.
            'book[categoriesText]' => 'science fiction',
        ]));

        $categories = $this->entityManager->getRepository(Category::class)->findAll();

        self::assertCount(1, $categories, 'Une categorie proche ne doit pas etre dupliquee.');
        self::assertSame('Science-fiction', $categories[0]->getName());
    }

    public function testDeletingCategoryKeepsTheBook(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Germinal',
            'book[categoriesText]' => 'Classique',
        ]));

        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'classique']);
        self::assertNotNull($category);

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->findOneBy(['title' => 'Germinal']);

        self::assertNotNull($book, 'Supprimer une categorie ne doit jamais supprimer le livre.');
        self::assertCount(0, $book->getCategories());
    }

    public function testLookupRejectsInvalidIsbn(): void
    {
        $this->client->loginUser($this->createAdmin());

        // Somme de controle invalide : aucune requete externe ne doit partir.
        $this->client->request('GET', '/admin/livres/recherche-isbn/9780306406158');

        self::assertResponseStatusCodeSame(422);
    }
}
