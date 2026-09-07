<?php

declare(strict_types=1);

namespace App\Service\Metadata;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Catalogue général de la BnF (protocole SRU, réponses Dublin Core).
 *
 * Interrogé en dernier : c'est souvent la seule source à connaître les éditions
 * françaises, mais ses champs sont de la prose catalographique qu'il faut
 * nettoyer (mention de responsabilité, dates de vie, ville d'édition).
 */
#[AutoconfigureTag('app.metadata_provider', ['priority' => 60])]
class BnfProvider extends AbstractMetadataProvider
{
    private const string ENDPOINT = 'https://catalogue.bnf.fr/api/SRU';
    private const string NS_SRW = 'http://www.loc.gov/zing/srw/';
    private const string NS_DC = 'http://purl.org/dc/elements/1.1/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'bnf';
    }

    public function createRequest(string $isbn13): ?ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'version' => '1.2',
                    'operation' => 'searchRetrieve',
                    // bib.isbn ne matche pas les ISBN-13 bruts : la BnF les stocke tiretés
                    // ou en ISBN-10. bib.fuzzyIsbn normalise avant comparaison.
                    'query' => \sprintf('bib.fuzzyIsbn all "%s"', $isbn13),
                    'recordSchema' => 'dublincore',
                    'maximumRecords' => '1',
                ],
                'timeout' => self::TIMEOUT,
            ]);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('BnF injoignable : {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function parse(ResponseInterface $response, string $isbn13): ?BookMetadata
    {
        try {
            $xml = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('BnF indisponible pour {isbn}: {error}', [
                'isbn' => $isbn13,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $record = $this->parseRecord($xml);

        if (null === $record) {
            return null;
        }

        $title = $this->cleanTitle($this->first($record, 'title'));

        if (null === $title) {
            return null;
        }

        return new BookMetadata(
            title: $title,
            authors: $this->cleanCreators($this->all($record, 'creator')),
            publisher: $this->cleanPublisher($this->first($record, 'publisher')),
            publishedYear: $this->year($this->first($record, 'date')),
            language: $this->language($this->first($record, 'language')),
            description: $this->first($record, 'description'),
            categories: $this->all($record, 'subject'),
            isbn13: $isbn13,
            sources: [$this->getName()],
        );
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    private function parseRecord(string $xml): ?array
    {
        if ('' === trim($xml)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET coupe tout accès réseau du parseur : pas de résolution
            // d'entité externe, donc pas de surface XXE.
            $document = simplexml_load_string($xml, options: \LIBXML_NONET | \LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (false === $document) {
            return null;
        }

        $document->registerXPathNamespace('srw', self::NS_SRW);
        $records = $document->xpath('//srw:recordData/*') ?: [];

        if ([] === $records) {
            return null;
        }

        $fields = [];
        foreach ($records[0]->children(self::NS_DC) as $name => $value) {
            $text = trim((string) $value);
            if ('' !== $text) {
                $fields[(string) $name][] = $text;
            }
        }

        return [] === $fields ? null : $fields;
    }

    /**
     * @param array<string, array<int, string>> $record
     */
    private function first(array $record, string $field): ?string
    {
        return $record[$field][0] ?? null;
    }

    /**
     * @param array<string, array<int, string>> $record
     *
     * @return array<int, string>
     */
    private function all(array $record, string $field): array
    {
        return array_values(array_unique($record[$field] ?? []));
    }

    /**
     * Le titre BnF porte la mention de responsabilité :
     * "1984 / George Orwell ; trad. de l'anglais par Amélie Audiberti".
     */
    private function cleanTitle(?string $title): ?string
    {
        if (null === $title) {
            return null;
        }

        $title = explode(' / ', $title)[0];
        $title = trim($title, " \t\n\r\0\x0B.,;:");

        return '' === $title ? null : $title;
    }

    /**
     * L'éditeur est suffixé de sa ville : "Gallimard (Paris)".
     */
    private function cleanPublisher(?string $publisher): ?string
    {
        if (null === $publisher) {
            return null;
        }

        $publisher = preg_replace('/\s*\([^)]*\)\s*$/u', '', $publisher) ?? $publisher;
        $publisher = trim($publisher);

        return '' === $publisher ? null : $publisher;
    }

    /**
     * La BnF renvoie "Orwell, George (1903-1950). Auteur du texte" : il faut retirer
     * la fonction et les dates de vie avant d'inverser nom et prénom.
     *
     * @param array<int, string> $creators
     *
     * @return array<int, string>
     */
    private function cleanCreators(array $creators): array
    {
        $names = [];

        foreach ($creators as $creator) {
            $name = preg_replace('/\.\s*(Auteur|Traducteur|Éditeur|Illustrateur|Préfacier)[^.]*\.?\s*$/ui', '', $creator) ?? $creator;
            $name = preg_replace('/\s*\(\d{4}\??-?\d{0,4}\??\)/u', '', $name) ?? $name;
            $name = trim($name, " \t\n\r\0\x0B.,;");

            if (str_contains($name, ',')) {
                [$last, $first] = array_map(trim(...), explode(',', $name, 2));
                $name = '' !== $first ? $first.' '.$last : $last;
            }

            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function language(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return match (strtolower($value)) {
            'fre', 'fra', 'fr' => 'fr',
            'eng', 'en' => 'en',
            default => substr(strtolower($value), 0, 8),
        };
    }
}
