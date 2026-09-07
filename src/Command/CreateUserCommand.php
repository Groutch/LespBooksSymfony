<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Création du premier administrateur, exécutable en SSH sur l'hébergement.
 *
 * Le mot de passe n'est jamais passé en argument : il serait conservé dans
 * l'historique du shell et visible dans la liste des processus.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un compte (administrateur par défaut) ou met à jour son mot de passe.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail de connexion')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom affiché')
            ->addOption('lecteur', null, InputOption::VALUE_NONE, 'Créer un simple lecteur au lieu d\'un administrateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email') ?? $io->ask('Adresse e-mail');
        $email = is_string($email) ? trim($email) : '';

        if ('' === $email) {
            $io->error('Une adresse e-mail est obligatoire.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Mot de passe (saisie masquée)');

        if (!\is_string($password) || \strlen($password) < 8) {
            $io->error('Le mot de passe doit contenir au moins 8 caractères.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNew = null === $user;

        if (null === $user) {
            $user = new User();
            $user->setEmail($email);
        }

        $displayName = $input->getOption('name');
        if (\is_string($displayName) && '' !== trim($displayName)) {
            $user->setDisplayName(trim($displayName));
        } elseif ('' === $user->getDisplayName()) {
            $user->setDisplayName(explode('@', $email)[0]);
        }

        $user->setRoles($input->getOption('lecteur') ? [User::ROLE_USER] : [User::ROLE_ADMIN]);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s : %s', $violation->getPropertyPath(), (string) $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf(
            '%s : %s (%s)',
            $isNew ? 'Compte créé' : 'Mot de passe mis à jour',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
