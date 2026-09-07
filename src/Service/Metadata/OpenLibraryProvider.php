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

    /**
     * Plafond volontairement bas : ces catégories servent au rangement en rayon.
     */
    private const int MAX_SUBJECTS = 6;

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
            categories: $this->cleanSubjects($this->names($volume['subjects'] ?? null)),
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

    /**
     * OpenLibrary expose des centaines de "subjects" contributifs, dont des cotes
     * de bibliothèque ("Pr6029.r8 n49 2003") et des tags techniques
     * ("open_syllabus_project"). Sans ce filtrage, un seul scan créerait des
     * dizaines de catégories inutilisables pour le rangement physique.
     *
     * @param array<int, string> $subjects
     *
     * @return array<int, string>
     */
    private function cleanSubjects(array $subjects): array
    {
        $kept = [];

        foreach ($subjects as $subject) {
            if (str_contains($subject, '_') || str_contains($subject, '--')) {
                continue;
            }

            $length = mb_strlen($subject);
            if ($length < 3 || $length > 40) {
                continue;
            }

            // Cotes et références catalographiques : mélange de lettres et de chiffres.
            if (1 === preg_match('/\d/', $subject) && 1 === preg_match('/[a-z]\d|\d[a-z]/i', $subject)) {
                continue;
            }

            $kept[] = mb_convert_case($subject, \MB_CASE_TITLE, 'UTF-8');

            if (\count($kept) >= self::MAX_SUBJECTS) {
                break;
            }
        }

        return array_values(array_unique($kept));
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
