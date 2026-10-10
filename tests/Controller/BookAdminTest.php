<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Category;
use App\Entity\Genre;
use App\Entity\Loan;
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
     * Le champ fichier prenait la classe `field` des champs de saisie : fond
     * blanc, bouton natif invisible. Il a desormais son propre habillage, et
     * n'accepte que les formats que la contrainte Image laisse passer.
     */
    public function testTheCoverUploadButtonHasItsOwnStyle(): void
    {
        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/livres/nouveau');

        self::assertSelectorExists('input[type="file"][accept="image/jpeg,image/png,image/webp"]');
        self::assertSelectorExists('input[type="file"][class*="file:bg-accent-wash"]');
        self::assertSelectorNotExists('input[type="file"].field');
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
        // Sa fiche, d'ou l'on peut le preter aussitot.
        self::assertSame('/admin/livres/'.$book->getId(), $data['existing']['url']);
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

        $book = $this->entityManager->getRepository(Book::class)->findOneBy(['title' => 'Le Mystère de la chambre jaune']);

        self::assertNotNull($book);
        // Sur sa fiche, pour relire ce qui vient d'etre enregistre.
        self::assertResponseRedirects('/admin/livres/'.$book->getId());
        self::assertSame('Gaston Leroux', $book->getAuthorNames());
        self::assertCount(2, $book->getCategories());
    }

    /**
     * La fiche recapitulative : ou est le livre, s'il est prete, a qui, et son
     * historique. C'est la que mene le scan d'un livre deja au catalogue.
     */
    public function testTheBookSheetSummarizesTheBookAndItsLoans(): void
    {
        $rayon = (new Genre())->setName('Policier')->setSlug('policier');
        $book = (new Book())->setTitle('Le Chien jaune')->setGenre($rayon)->setShelfLocation('étagère 2')
            ->setIsbn13('9782253142256');
        $ancien = (new Loan())->setBorrowerFirstName('Marie')->setBorrowerLastName('Curie')
            ->setBorrowedAt(new \DateTimeImmutable('today -20 days'))
            ->setReturnedAt(new \DateTimeImmutable('today -10 days'));
        $enCours = (new Loan())->setBorrowerFirstName('Jean')->setBorrowerLastName('Martin')
            ->setBorrowedAt(new \DateTimeImmutable('today -2 days'))
            ->setDueAt(new \DateTimeImmutable('today +26 days'));
        $book->addLoan($ancien)->addLoan($enCours);

        foreach ([$rayon, $book, $ancien, $enCours] as $entite) {
            $this->entityManager->persist($entite);
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        $this->entityManager->clear();
        $this->client->request('GET', '/admin/livres/'.$book->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le Chien jaune');
        self::assertSelectorTextContains('main', 'Prêté à Jean Martin');
        self::assertSelectorExists('form[action="/admin/prets/'.$enCours->getId().'/rendre"]');
        self::assertSelectorTextContains('a[href="/admin/livres?genre=policier"]', 'Policier');
        self::assertSelectorTextContains('main', 'étagère 2');
        self::assertSelectorTextContains('main', '9782253142256');
        self::assertSelectorTextContains('section', 'Marie Curie');
        self::assertSelectorExists('a[href="/livre/'.$book->getId().'"]');
    }

    public function testAnAvailableBookCanBeLentFromItsSheet(): void
    {
        $book = (new Book())->setTitle('Germinal');
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/livres/'.$book->getId());

        self::assertSelectorTextContains('main', 'Disponible');
        self::assertSelectorTextContains('a[href="/admin/prets/livre/'.$book->getId().'/nouveau"]', 'Prêter ce livre');
        self::assertSelectorTextContains('main', 'jamais été prêté');
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
