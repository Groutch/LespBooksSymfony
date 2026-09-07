<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Genre;
use App\Tests\AdminWebTestCase;

class GenreAdminTest extends AdminWebTestCase
{
    public function testAdminCanCreateAShelf(): void
    {
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/rayons');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Ajouter le rayon')->form([
            'genre[name]' => 'Bandes dessinées',
        ]));

        self::assertResponseRedirects('/admin/rayons');

        $genre = $this->entityManager->getRepository(Genre::class)->findOneBy(['name' => 'Bandes dessinées']);

        self::assertNotNull($genre);
        self::assertSame('bandes-dessinees', $genre->getSlug());
    }

    public function testTwoShelvesCannotShareTheSameName(): void
    {
        $this->createGenre('Policier');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/rayons');
        $this->client->submit($crawler->selectButton('Ajouter le rayon')->form([
            'genre[name]' => 'policier',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(Genre::class)->findAll());
    }

    public function testDeletingAShelfKeepsItsBooks(): void
    {
        $genre = $this->createGenre('Policier');

        $book = (new Book())->setTitle('Le Mystère de la chambre jaune')->setGenre($genre);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/rayons/'.$genre->getId().'/modifier');
        $this->client->submit($crawler->selectButton('Supprimer ce rayon')->form());

        self::assertResponseRedirects('/admin/rayons');
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->find($book->getId());

        self::assertNotNull($book, 'Supprimer un rayon ne doit jamais supprimer les livres.');
        self::assertNull($book->getGenre());
    }

    public function testShelvesRejectAnonymousVisitors(): void
    {
        $this->client->request('GET', '/admin/rayons');

        self::assertResponseRedirects('/connexion');
    }

    private function createGenre(string $name): Genre
    {
        $genre = (new Genre())->setName($name)->setSlug(strtolower($name));

        $this->entityManager->persist($genre);
        $this->entityManager->flush();

        return $genre;
    }
}
