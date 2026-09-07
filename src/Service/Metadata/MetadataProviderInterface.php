<?php

declare(strict_types=1);

namespace App\Service\Metadata;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Source de métadonnées interrogeable par ISBN.
 *
 * L'interrogation est volontairement découpée en deux temps : MetadataFetcher
 * lance d'abord toutes les requêtes (le client HTTP Symfony est paresseux, elles
 * partent donc en parallèle), puis lit les réponses. La latence totale devient
 * celle de la source la plus lente au lieu de la somme des trois.
 */
interface MetadataProviderInterface
{
    /**
     * Prépare la requête sans attendre la réponse.
     *
     * @param string $isbn13 ISBN-13 déjà validé par IsbnNormalizer
     */
    public function createRequest(string $isbn13): ?ResponseInterface;

    /**
     * Lit la réponse et la convertit en métadonnées exploitables.
     */
    public function parse(ResponseInterface $response, string $isbn13): ?BookMetadata;

    public function getName(): string;
}
