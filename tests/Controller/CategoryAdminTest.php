<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Category;
use App\Tests\AdminWebTestCase;

class CategoryAdminTest extends AdminWebTestCase
{
    public function testAdminCanCreateCategoryWithGeneratedSlug(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/categories/nouvelle');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Créer')->form([
            'category[name]' => 'Bandes dessinées',
            'category[shelfCode]' => 'B2',
        ]));

        self::assertResponseRedirects('/admin/categories');

        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['name' => 'Bandes dessinées']);

        self::assertNotNull($category);
        self::assertSame('bandes-dessinees', $category->getSlug());
    }

    public function testCreatingACategoryWithAnExistingSlugIsRefused(): void
    {
        $this->createCategory('Science-fiction');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/categories/nouvelle');
        $this->client->submit($crawler->selectButton('Créer')->form([
            'category[name]' => 'science fiction',
        ]));

        // Symfony re-affiche un formulaire invalide avec un 422.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('li', 'Une catégorie porte déjà ce nom.');
        self::assertCount(1, $this->entityManager->getRepository(Category::class)->findAll());
    }

    public function testCreatingANearDuplicateAsksForConfirmationFirst(): void
    {
        $this->createCategory('Science-fiction');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/categories/nouvelle');
        $crawler = $this->client->submit($crawler->selectButton('Créer')->form([
            'category[name]' => 'Sciences fiction',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button', 'Créer quand même');
        self::assertCount(1, $this->entityManager->getRepository(Category::class)->findAll());

        // Deuxieme envoi : l'administrateur assume la creation.
        $this->client->submit($crawler->selectButton('Créer quand même')->form());

        self::assertResponseRedirects('/admin/categories');
        self::assertCount(2, $this->entityManager->getRepository(Category::class)->findAll());
    }

    public function testMergingMovesBooksAndRemovesTheSourceCategory(): void
    {
        $target = $this->createCategory('Science-fiction');
        $source = $this->createCategory('SF');
        $book = $this->createBook('Fondation', $source);

        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/categories/'.$source->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Fusionner')->form(['target' => (string) $target->getId()]));

        self::assertResponseRedirects('/admin/categories');
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->find($book->getId());

        self::assertNotNull($book, 'Une fusion ne doit jamais supprimer un livre.');
        self::assertCount(1, $book->getCategories());
        self::assertSame('Science-fiction', $book->getCategories()->first()->getName());
        self::assertNull($this->entityManager->getRepository(Category::class)->find($source->getId()));
    }

    public function testDeletingCategoryFromAdminKeepsTheBooks(): void
    {
        $category = $this->createCategory('Policier');
        $book = $this->createBook('Le Mystère de la chambre jaune', $category);

        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/categories/'.$category->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Supprimer cette catégorie')->form());

        self::assertResponseRedirects('/admin/categories');
        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->getRepository(Book::class)->find($book->getId()));
        self::assertNull($this->entityManager->getRepository(Category::class)->find($category->getId()));
    }

    public function testCategoryScreenRejectsAnonymousVisitors(): void
    {
        $this->client->request('GET', '/admin/categories');

        self::assertResponseRedirects('/connexion');
    }

    private function createCategory(string $name): Category
    {
        $category = (new Category())
            ->setName($name)
            ->setSlug(strtolower(str_replace(' ', '-', $name)));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function createBook(string $title, Category $category): Book
    {
        $book = (new Book())->setTitle($title);
        $book->addCategory($category);

        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }
}
