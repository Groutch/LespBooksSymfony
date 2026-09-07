<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AdminWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
    }

    protected function createAdmin(): User
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
