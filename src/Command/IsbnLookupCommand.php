<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Metadata\MetadataFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic des sources externes, exécutable en SSH sur l'hébergement.
 */
#[AsCommand(
    name: 'app:isbn:lookup',
    description: 'Interroge les sources externes pour un ISBN et affiche les métadonnées fusionnées.',
)]
class IsbnLookupCommand extends Command
{
    public function __construct(private readonly MetadataFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('isbn', InputArgument::REQUIRED, 'ISBN-10 ou ISBN-13, avec ou sans tirets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $isbn */
        $isbn = $input->getArgument('isbn');

        $metadata = $this->fetcher->fetchByIsbn($isbn);

        if (null === $metadata) {
            $io->error(\sprintf('Aucune métadonnée trouvée pour "%s" (ISBN invalide ou inconnu des sources).', $isbn));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Trouvé via : %s', implode(', ', $metadata->sources)));
        $io->definitionList(
            ['Titre' => $metadata->title ?? '—'],
            ['Sous-titre' => $metadata->subtitle ?? '—'],
            ['Auteurs' => [] === $metadata->authors ? '—' : implode(', ', $metadata->authors)],
            ['Éditeur' => $metadata->publisher ?? '—'],
            ['Année' => $metadata->publishedYear ?? '—'],
            ['Pages' => $metadata->pageCount ?? '—'],
            ['Langue' => $metadata->language ?? '—'],
            ['ISBN-13' => $metadata->isbn13 ?? '—'],
            ['ISBN-10' => $metadata->isbn10 ?? '—'],
            ['Catégories' => [] === $metadata->categories ? '—' : implode(' | ', $metadata->categories)],
            ['Couverture' => $metadata->coverUrl ?? '—'],
        );

        return Command::SUCCESS;
    }
}
