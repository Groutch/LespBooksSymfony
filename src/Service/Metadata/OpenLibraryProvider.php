<?php

declare(strict_types=1);

namespace App\Service\Metadata;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AutoconfigureTag('app.metadata_provider', ['priority' => 80])]
class OpenLibraryProvider extends AbstractMetadataProvider
{
    private const string ENDPOINT = 'https://openlibrary.org/api/books';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'open_library';
    }

    public function createRequest(string $isbn13): ?ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'bibkeys' => 'ISBN:'.$isbn13,
                    'format' => 'json',
                    'jscmd' => 'data',
                ],
                'timeout' => self::TIMEOUT,
            ]);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('OpenLibrary injoignable : {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function parse(ResponseInterface $response, string $isbn13): ?BookMetadata
    {
        $key = 'ISBN:'.$isbn13;

        try {
            /** @var array<string, array<string, mixed>> $data */
            $data = $response->toArray(false);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->warning('OpenLibrary indisponible pour {isbn}: {error}', [
                'isbn' => $isbn13,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $volume = $data[$key] ?? null;

        if (!\is_array($volume)) {
            return null;
        }

        return new BookMetadata(
            title: $this->string($volume['title'] ?? null),
            subtitle: $this->string($volume['subtitle'] ?? null),
            authors: $this->names($volume['authors'] ?? null),
            publisher: $this->names($volume['publishers'] ?? null)[0] ?? null,
            publishedYear: $this->year($volume['publish_date'] ?? null),
            pageCount: $this->positiveInt($volume['number_of_pages'] ?? null),
            // Les "subjects" contributifs d'OpenLibrary sont volontairement ignores :
            // un seul scan produisait une dizaine de categories anglaises et
            // inexploitables pour ranger un livre sur une etagere.
            coverUrl: $this->cover($volume['cover'] ?? null),
            isbn13: $isbn13,
            sources: [$this->getName()],
        );
    }

    /**
     * OpenLibrary renvoie des listes d'objets {name, url}.
     *
     * @return array<int, string>
     */
    private function names(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $name = $this->string($item['name'] ?? null);
                if (null !== $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function cover(mixed $cover): ?string
    {
        if (!\is_array($cover)) {
            return null;
        }

        foreach (['large', 'medium', 'small'] as $size) {
            $url = $this->string($cover[$size] ?? null);
            if (null !== $url) {
                return $this->forceHttps($url);
            }
        }

        return null;
    }
}
