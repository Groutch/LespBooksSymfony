<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\Loan;
use App\Tests\AdminWebTestCase;

class LoanAdminTest extends AdminWebTestCase
{
    public function testAdminCanLendThenReturnABook(): void
    {
        $book = $this->createBook('L\'Étranger');
        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/prets/livre/'.$book->getId().'/nouveau');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Enregistrer le prêt')->form([
            'loan[borrowerFirstName]' => 'Marie',
            'loan[borrowerLastName]' => 'Dupont',
            'loan[borrowedAt]' => '2026-09-01',
            'loan[dueAt]' => '2026-09-29',
        ]));

        self::assertResponseRedirects('/admin/prets');
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->find($book->getId());
        self::assertFalse($book->isAvailable());
        self::assertSame('Marie Dupont', $book->getActiveLoan()->getBorrowerName());

        $crawler = $this->client->request('GET', '/admin/prets');
        $this->client->submit($crawler->selectButton('Rendre')->form());

        self::assertResponseRedirects('/admin/prets');
        $this->entityManager->clear();

        $book = $this->entityManager->getRepository(Book::class)->find($book->getId());
        self::assertTrue($book->isAvailable(), 'Un livre rendu doit redevenir disponible.');
    }

    public function testABookCannotBeLentTwiceAtTheSameTime(): void
    {
        $book = $this->createBook('Dune');
        $this->createLoan($book, dueAt: new \DateTimeImmutable('2026-10-01'));

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin/prets/livre/'.$book->getId().'/nouveau');

        self::assertResponseRedirects('/admin/prets');
    }

    public function testExtendingWithoutDatePushesTheDueDate(): void
    {
        $book = $this->createBook('Fondation');
        $loan = $this->createLoan($book, dueAt: new \DateTimeImmutable('2026-09-10'));

        $this->client->loginUser($this->createAdmin());

        $crawler = $this->client->request('GET', '/admin/prets');
        // Champ vidé : on vérifie le report automatique, pas la valeur pré-remplie.
        $this->client->submit($crawler->selectButton('Prolonger')->form(['dueAt' => '']));

        self::assertResponseRedirects('/admin/prets');
        $this->entityManager->clear();

        $loan = $this->entityManager->getRepository(Loan::class)->find($loan->getId());
        self::assertSame('2026-10-08', $loan->getDueAt()->format('Y-m-d'));
    }

    public function testExtendingWithoutCsrfTokenIsRejected(): void
    {
        $book = $this->createBook('Le Horla');
        $loan = $this->createLoan($book, dueAt: new \DateTimeImmutable('2026-09-10'));

        $this->client->loginUser($this->createAdmin());
        $this->client->request('POST', '/admin/prets/'.$loan->getId().'/prolonger', ['dueAt' => '2026-12-31']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Promesse faite sur /confidentialite : trois mois apres le retour, nom et
     * note disparaissent. Le pret reste, pour l'historique du livre.
     */
    public function testBorrowerNamesAreErasedThreeMonthsAfterTheReturn(): void
    {
        $ancien = $this->createLoan($this->createBook('Bel-Ami'), dueAt: null)
            ->setBorrowedAt(new \DateTimeImmutable('today -4 months'))
            ->setReturnedAt(new \DateTimeImmutable('today -3 months'))
            ->setNotes('Rappeler au 06 12 34 56 78');
        $recent = $this->createLoan($this->createBook('Nana'), dueAt: null)
            ->setBorrowedAt(new \DateTimeImmutable('today -4 months'))
            ->setReturnedAt(new \DateTimeImmutable('today -3 months +1 day'));
        $enCours = $this->createLoan($this->createBook('Thérèse Raquin'), dueAt: null)
            ->setBorrowedAt(new \DateTimeImmutable('today -1 year'));
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        // Comme en production, la requete part d'un EntityManager vide : sinon
        // Doctrine resservirait les prets deja en memoire, sans voir l'effacement
        // fait en DQL.
        $this->entityManager->clear();
        $this->client->request('GET', '/admin/prets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Nom effacé');
        self::assertSelectorTextNotContains('main', '06 12 34 56 78');

        $this->entityManager->clear();
        $prets = $this->entityManager->getRepository(Loan::class);

        $ancien = $prets->find($ancien->getId());
        self::assertTrue($ancien->isAnonymized());
        self::assertNull($ancien->getNotes());
        self::assertNotNull($ancien->getReturnedAt(), 'Le pret lui-meme est conserve.');

        self::assertSame('Jean Martin', $prets->find($recent->getId())->getBorrowerName());
        // Un pret en cours n'est jamais concerne, quelle que soit son anciennete.
        self::assertSame('Jean Martin', $prets->find($enCours->getId())->getBorrowerName());
    }

    /** Page d'ouverture de l'application installee : le menage y passe aussi. */
    public function testTheDashboardAlsoErasesExpiredNames(): void
    {
        $loan = $this->createLoan($this->createBook('Germinal'), dueAt: null)
            ->setBorrowedAt(new \DateTimeImmutable('today -1 year'))
            ->setReturnedAt(new \DateTimeImmutable('today -6 months'));
        $this->entityManager->flush();

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->getRepository(Loan::class)->find($loan->getId())->isAnonymized());
    }

    public function testLoanScreenRejectsAnonymousVisitors(): void
    {
        $this->client->request('GET', '/admin/prets');

        self::assertResponseRedirects('/connexion');
    }

    private function createBook(string $title): Book
    {
        $book = (new Book())->setTitle($title);

        $this->entityManager->persist($book);
        $this->entityManager->flush();

        return $book;
    }

    private function createLoan(Book $book, ?\DateTimeImmutable $dueAt): Loan
    {
        $loan = (new Loan())
            ->setBook($book)
            ->setBorrowerFirstName('Jean')
            ->setBorrowerLastName('Martin')
            ->setBorrowedAt(new \DateTimeImmutable('2026-09-01'))
            ->setDueAt($dueAt);

        $book->addLoan($loan);
        $this->entityManager->persist($loan);
        $this->entityManager->flush();

        return $loan;
    }
}
