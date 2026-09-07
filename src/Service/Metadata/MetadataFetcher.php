<?php

declare(strict_types=1);

namespace App\Service\Metadata;

use App\Service\IsbnNormalizer;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Interroge les sources dans l'ordre de priorité et fusionne les résultats.
 *
 * Chaque source ne sert qu'à combler les champs encore vides, ce qui permet
 * d'obtenir une fiche complète même quand aucune source ne l'est.
 */
class MetadataFetcher
{
    /**
     * @param iterable<MetadataProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.metadata_provider')]
        private readonly iterable $providers,
        private readonly IsbnNormalizer $isbnNormalizer,
    ) {
    }

    /**
     * @param string $rawIsbn code scanné ou saisi, non encore validé
     */
    public function fetchByIsbn(string $rawIsbn): ?BookMetadata
    {
        $isbn13 = $this->isbnNormalizer->normalize($rawIsbn);

        if (null === $isbn13) {
            return null;
        }

        // Phase 1 : toutes les requêtes sont lancées avant d'en lire la moindre,
        // ce qui les fait voyager en parallèle.
        $pending = [];
        foreach ($this->providers as $provider) {
            $response = $provider->createRequest($isbn13);

            if (null !== $response) {
                $pending[] = [$provider, $response];
            }
        }

        // Phase 2 : lecture dans l'ordre de priorité, chaque source comblant les trous.
        $result = null;
        foreach ($pending as [$provider, $response]) {
            $metadata = $provider->parse($response, $isbn13);

            if (null === $metadata || $metadata->isEmpty()) {
                continue;
            }

            $result = null === $result ? $metadata : $result->mergeFallback($metadata);
        }

        if (null === $result) {
            return null;
        }

        return $result->mergeFallback(new BookMetadata(
            isbn13: $isbn13,
            isbn10: $this->isbnNormalizer->toIsbn10($isbn13),
        ));
    }
}
