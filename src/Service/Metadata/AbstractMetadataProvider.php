<?php

declare(strict_types=1);

namespace App\Service\Metadata;

/**
 * Helpers de lecture défensive des réponses externes.
 *
 * Les API renvoient des structures hétérogènes : chaque valeur est donc typée
 * explicitement avant d'entrer dans le domaine.
 */
abstract class AbstractMetadataProvider implements MetadataProviderInterface
{
    /**
     * Budget volontairement court : le scan doit rester fluide sur mobile, quitte
     * à se passer d'une source trop lente.
     */
    protected const int TIMEOUT = 6;

    protected function string(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    protected function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $string = $this->string($item);
            if (null !== $string) {
                $items[] = $string;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Extrait l'année d'un format libre : "2005", "2005-06-12", "impr. 2005".
     */
    protected function year(mixed $value): ?int
    {
        if (!\is_string($value) || 1 !== preg_match('/(\d{4})/', $value, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Les couvertures sont parfois servies en HTTP, ce qui casserait la page en HTTPS.
     */
    protected function forceHttps(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }

        return str_starts_with($url, 'http://') ? substr_replace($url, 'https://', 0, 7) : $url;
    }
}
