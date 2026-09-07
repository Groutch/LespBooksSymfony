<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Book;
use App\Entity\BookImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BookImagePersistenceTest extends KernelTestCase
{
    /**
     * Regression : la colonne s'appelait « primary », mot reserve que MySQL
     * refusait dans l'INSERT genere par Doctrine.
     */
    public function testPrimaryCoverCanBePersisted(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $book = (new Book())->setTitle('Livre avec couverture');
        $image = (new BookImage())
            ->setPrimary(true)
            ->setPosition(0)
            ->setSourceUrl('https://example.test/couverture.jpg');
        $book->addImage($image);

        $entityManager->persist($book);
        $entityManager->flush();
        $entityManager->clear();

        $reloaded = $entityManager->getRepository(Book::class)->find($book->getId());

        self::assertNotNull($reloaded->getPrimaryImage());
        self::assertTrue($reloaded->getPrimaryImage()->isPrimary());
    }
}
