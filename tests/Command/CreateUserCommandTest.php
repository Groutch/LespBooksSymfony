<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CreateUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM app_user');

        $this->command = new CommandTester((new Application(self::$kernel))->find('app:user:create'));
    }

    public function testMismatchedConfirmationLeavesNothingBehind(): void
    {
        $this->command->setInputs(['motdepasse-de-test', 'motdepasse-different']);
        $exitCode = $this->command->execute(['--email' => 'admin@lespouey.test']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('ne correspondent pas', $this->command->getDisplay());
        self::assertCount(0, $this->entityManager->getRepository(User::class)->findAll());
    }

    public function testConfirmedPasswordCreatesAnAdmin(): void
    {
        $this->command->setInputs(['motdepasse-de-test', 'motdepasse-de-test']);
        $exitCode = $this->command->execute(['--email' => 'admin@lespouey.test']);

        self::assertSame(Command::SUCCESS, $exitCode);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@lespouey.test']);

        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
    }
}
