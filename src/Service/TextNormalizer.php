<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Normalisation et comparaison de libellés, base de la déduplication
 * des catégories et des auteurs lors de l'import automatique.
 */
class TextNormalizer
{
    private readonly SluggerInterface $slugger;

    public function __construct(?SluggerInterface $slugger = null)
    {
        $this->slugger = $slugger ?? new AsciiSlugger('fr');
    }

    /**
     * "Science-Fiction & Fantasy " et "science fiction et fantasy" donnent la même clé.
     */
    public function slugify(string $value): string
    {
        $value = str_replace(['&', '+'], ' et ', $value);

        return $this->slugger->slug($value)->lower()->toString();
    }

    /**
     * Titre lisible : espaces normalisés, casse d'origine conservée.
     */
    public function cleanLabel(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Score de proximité entre 0 et 1, calculé sur les formes normalisées.
     */
    public function similarity(string $a, string $b): float
    {
        $a = $this->slugify($a);
        $b = $this->slugify($b);

        if ('' === $a || '' === $b) {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        // Un libellé entièrement contenu dans l'autre est presque toujours la même notion
        // ("policier" vs "roman policier"), ce que la distance d'édition sous-estime.
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return 0.92;
        }

        $distance = levenshtein($a, $b);
        $maxLength = max(\strlen($a), \strlen($b));

        return 1.0 - ($distance / $maxLength);
    }
}
