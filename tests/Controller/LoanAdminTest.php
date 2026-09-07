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
