<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BookAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
    }

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

    private function createAdmin(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail('admin@test.local')
            ->setDisplayName('Admin')
            ->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'motdepasse-de-test'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function purge(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        foreach (['book_category', 'book_author', 'book_image', 'loan', 'book', 'category', 'genre', 'author', 'app_user'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE '.$table);
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
