<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

/**
 * Récupère la couverture proposée par une source externe.
 *
 * L'URL provient d'une réponse d'API, donc de données non maîtrisées : le schéma
 * et l'hôte sont validés contre une liste blanche, et le contenu doit réellement
 * être une image, pour ne pas transformer ce service en relais de requêtes.
 */
class CoverDownloader
{
    private const int MAX_BYTES = 5_000_000;

    private const array ALLOWED_HOSTS = [
        'books.google.com',
        'books.googleusercontent.com',
        'covers.openlibrary.org',
        'catalogue.bnf.fr',
    ];

    private const array ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Un `File` nu serait ignoré en silence par VichUploader : seul un
     * ReplacingFile déclenche la prise en charge d'un fichier déjà sur disque.
     */
    public function download(string $url, string $reference): ?ReplacingFile
    {
        if (!$this->isAllowed($url)) {
            $this->logger->warning('Couverture refusée (hôte non autorisé) : {url}', ['url' => $url]);

            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Téléchargement de couverture impossible : {error}', ['error' => $e->getMessage()]);

            return null;
        }

        if ('' === $content || \strlen($content) > self::MAX_BYTES) {
            return null;
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);

        if (!\is_string($mime) || !isset(self::ALLOWED_MIME[$mime])) {
            $this->logger->warning('Couverture refusée (type {mime} non autorisé).', ['mime' => $mime]);

            return null;
        }

        $path = \sprintf(
            '%s/couverture-%s.%s',
            sys_get_temp_dir(),
            preg_replace('/[^A-Za-z0-9_-]/', '', $reference) ?: 'livre',
            self::ALLOWED_MIME[$mime],
        );

        if (false === file_put_contents($path, $content)) {
            return null;
        }

        return new ReplacingFile($path, removeReplacedFile: true);
    }

    private function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ('https' !== strtolower($parts['scheme'])) {
            return false;
        }

        return \in_array(strtolower($parts['host']), self::ALLOWED_HOSTS, true);
    }
}
