<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Validation et conversion des ISBN.
 *
 * Sert aussi de garde-fou : seules des valeurs strictement numériques validées
 * ici sont injectées dans les URLs des API externes.
 */
class IsbnNormalizer
{
    /**
     * Retire tirets, espaces et met le X final en majuscule.
     */
    public function clean(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9xX]/', '', $raw) ?? '');
    }

    public function isValidIsbn10(string $isbn): bool
    {
        if (1 !== preg_match('/^[0-9]{9}[0-9X]$/', $isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; ++$i) {
            $char = $isbn[$i];
            $value = 'X' === $char ? 10 : (int) $char;
            $sum += $value * (10 - $i);
        }

        return 0 === $sum % 11;
    }

    public function isValidIsbn13(string $isbn): bool
    {
        if (1 !== preg_match('/^[0-9]{13}$/', $isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 13; ++$i) {
            $sum += (int) $isbn[$i] * (0 === $i % 2 ? 1 : 3);
        }

        return 0 === $sum % 10;
    }

    public function toIsbn13(string $isbn10): ?string
    {
        if (!$this->isValidIsbn10($isbn10)) {
            return null;
        }

        $core = '978'.substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $core[$i] * (0 === $i % 2 ? 1 : 3);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $core.$checkDigit;
    }

    /**
     * Renvoie l'ISBN-13 canonique, ou null si le code scanné n'est pas un ISBN.
     *
     * Un code-barres EAN-13 de livre commence toujours par 978 ou 979 ; les autres
     * préfixes (977 pour la presse, 3 pour les produits) sont écartés.
     */
    public function normalize(string $raw): ?string
    {
        $clean = $this->clean($raw);

        if ($this->isValidIsbn13($clean)) {
            return str_starts_with($clean, '978') || str_starts_with($clean, '979') ? $clean : null;
        }

        if ($this->isValidIsbn10($clean)) {
            return $this->toIsbn13($clean);
        }

        return null;
    }

    /**
     * Version ISBN-10, uniquement disponible pour le préfixe 978.
     */
    public function toIsbn10(string $isbn13): ?string
    {
        if (!$this->isValidIsbn13($isbn13) || !str_starts_with($isbn13, '978')) {
            return null;
        }

        $core = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; ++$i) {
            $sum += (int) $core[$i] * (10 - $i);
        }

        $check = (11 - ($sum % 11)) % 11;

        return $core.(10 === $check ? 'X' : (string) $check);
    }
}
