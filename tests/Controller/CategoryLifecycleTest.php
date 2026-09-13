<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Category;
use App\Tests\AdminWebTestCase;

/**
 * Les catégories n'ont plus d'écran d'administration : ce sont de simples
 * mots-clés, saisis au fil du texte sur la fiche d'un livre. Leur cycle de vie
 * est donc entièrement gouverné par les livres, et c'est ce que vérifie ce test.
 */
class CategoryLifecycleTest extends AdminWebTestCase
{
    public function testCategoriesAreCreatedFromTheBookForm(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Dune',
            'book[categoriesText]' => 'Science-fiction, Aventure',
        ]));

        self::assertResponseRedirects('/admin/livres');
        self::assertCount(2, $this->entityManager->getRepository(Category::class)->findAll());
    }

    /**
     * Règle conservée : « science fiction » doit rejoindre « Science-fiction »
     * plutôt que d'en créer une jumelle. Elle était jusqu'ici éprouvée par l'écran
     * d'administration ; elle l'est désormais là où les catégories se saisissent.
     */
    public function testANearDuplicateJoinsTheExistingCategory(): void
    {
        $this->client->loginUser($this->createAdmin());

        $existing = (new Category())->setName('Science-fiction')->setSlug('science-fiction');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Fondation',
            'book[categoriesText]' => 'science fiction',
        ]));

        self::assertResponseRedirects('/admin/livres');

        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        self::assertCount(1, $categories, 'Un libellé proche ne doit pas créer de jumelle.');
        self::assertSame('Science-fiction', $categories[0]->getName());
    }

    /**
     * La règle nouvelle : sans livre pour la porter, une catégorie disparaît.
     * Sans ce ménage, les filtres du catalogue public se rempliraient d'étiquettes
     * fantômes ne renvoyant aucun résultat.
     */
    public function testEditingABookDeletesACategoryLeftWithoutAnyBook(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/livres/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Dune',
            'book[categoriesText]' => 'Science-fiction, Aventure',
        ]));

        $book = $this->entityManager->getRepository(Book::class)->findOneBy(['title' => 'Dune']);
        self::assertNotNull($book);

        // On ne garde qu'un thème : l'autre n'est plus porté par aucun livre.
        $crawler = $this->client->request('GET', '/admin/livres/'.$book->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'book[title]' => 'Dune',
            'book[categoriesText]' => 'Science-fiction',
        ]));

        self::assertResponseRedirects('/admin/livres');

        $this->entityManager->clear();
        $restantes = $this->entityManager->getRepository(Category::class)->findAll();

        self::assertCount(1, $restantes);
        self::assertSame('Science-fiction', $restantes[0]->getName());
    }

    /**
     * Règle conservée : supprimer une catégorie ne doit jamais emporter de livre.
     * Le `ON DELETE CASCADE` ne porte que sur la table de jointure.
     */
    public function testDeletingABookPurgesItsCategoriesWithoutTouchingOtherBooks(): void
    {
        $this->client->loginUser($this->createAdmin());

        $partagee = (new Category())->setName('Roman')->setSlug('roman');
        $exclusive = (new Category())->setName('Steampunk')->setSlug('steampunk');

        $condamne = (new Book())->setTitle('Le livre supprimé');
        $condamne->addCategory($partagee);
        $condamne->addCategory($exclusive);

        $survivant = (new Book())->setTitle('Le livre conservé');
        $survivant->addCategory($partagee);

        foreach ([$partagee, $exclusive, $condamne, $survivant] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/livres/'.$condamne->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Supprimer ce livre')->form());

        self::assertResponseRedirects('/admin/livres');

        $this->entityManager->clear();

        $livres = $this->entityManager->getRepository(Book::class)->findAll();
        self::assertCount(1, $livres, 'Purger les catégories ne doit jamais supprimer un livre.');
        self::assertSame('Le livre conservé', $livres[0]->getTitle());

        $categories = $this->entityManager->getRepository(Category::class)->findAll();
        self::assertCount(1, $categories, 'Seule la catégorie devenue orpheline disparaît.');
        self::assertSame('Roman', $categories[0]->getName());
    }

    public function testTheCategoryAdminScreensAreGone(): void
    {
        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/categories');

        self::assertResponseStatusCodeSame(404);
    }
}
