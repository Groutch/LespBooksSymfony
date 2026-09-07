<?php

declare(strict_types=1);

namespace App\Service\Metadata;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Source principale : métadonnées structurées et catégories courtes.
 *
 * Sans clé API, Google limite fortement les appels par IP (HTTP 429).
 */
#[AutoconfigureTag('app.metadata_provider', ['priority' => 100])]
class GoogleBooksProvider extends AbstractMetadataProvider
{
    private const string ENDPOINT = 'https://www.googleapis.com/books/v1/volumes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.google_books_api_key%')]
        private readonly string $apiKey = '',
    ) {
    }

    public function getName(): string
    {
        return 'google_books';
    }

    public function createRequest(string $isbn13): ?ResponseInterface
    {
        $query = ['q' => 'isbn:'.$isbn13];

        if ('' !== $this->apiKey) {
            $query['key'] = $this->apiKey;
        }

        try {
            return $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => $query,
                'timeout' => self::TIMEOUT,
            ]);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Google Books injoignable : {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function parse(ResponseInterface $response, string $isbn13): ?BookMetadata
    {
        try {
            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                // Sans clé API, Google renvoie fréquemment 429 (quota partagé par IP).
                $this->logger->warning('Google Books a répondu {status} pour {isbn}.', [
                    'status' => $statusCode,
                    'isbn' => $isbn13,
                ]);

                return null;
            }

            /** @var array{items?: array<int, array{volumeInfo?: array<string, mixed>}>} $data */
            $data = $response->toArray(false);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->warning('Google Books indisponible pour {isbn}: {error}', [
                'isbn' => $isbn13,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $volume = $data['items'][0]['volumeInfo'] ?? null;

        if (!\is_array($volume)) {
            return null;
        }

        return new BookMetadata(
            title: $this->string($volume['title'] ?? null),
            subtitle: $this->string($volume['subtitle'] ?? null),
            authors: $this->stringList($volume['authors'] ?? null),
            publisher: $this->string($volume['publisher'] ?? null),
            publishedYear: $this->year($volume['publishedDate'] ?? null),
            pageCount: $this->positiveInt($volume['pageCount'] ?? null),
            language: $this->string($volume['language'] ?? null),
            description: $this->string($volume['description'] ?? null),
            categories: $this->splitCategories($this->stringList($volume['categories'] ?? null)),
            coverUrl: $this->coverUrl($volume['imageLinks'] ?? null),
            isbn13: $isbn13,
            sources: [$this->getName()],
        );
    }

    /**
     * Google renvoie des libellés composés du type "Fiction / Science Fiction / General",
     * découpés ici en catégories distinctes exploitables pour le rangement.
     *
     * @param array<int, string> $categories
     *
     * @return array<int, string>
     */
    private function splitCategories(array $categories): array
    {
        $result = [];

        foreach ($categories as $category) {
            foreach (preg_split('#\s*/\s*#', $category) ?: [] as $part) {
                $part = trim($part);
                if ('' !== $part && 'General' !== $part) {
                    $result[] = $part;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function coverUrl(mixed $imageLinks): ?string
    {
        if (!\is_array($imageLinks)) {
            return null;
        }

        foreach (['extraLarge', 'large', 'medium', 'thumbnail', 'smallThumbnail'] as $size) {
            $url = $this->string($imageLinks[$size] ?? null);
            if (null !== $url) {
                return $this->forceHttps($url);
            }
        }

        return null;
    }
}
