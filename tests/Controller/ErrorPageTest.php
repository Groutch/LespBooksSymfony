<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * En production, Symfony sert ces gabarits a la place de sa page generique.
 * Les tests tournant en mode debug, ils passent par l'apercu /_error/{code},
 * qui les rend exactement comme la production les servira.
 */
class ErrorPageTest extends WebTestCase
{
    /** Le cas le plus probable est un lien mort vers une fiche : la recherche est rendue aussitot. */
    public function testTheNotFoundPageOffersTheSearchRightAway(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_error/404');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorExists('form[action="/catalogue"] input[name="q"]');
    }

    /** Un jeton CSRF perime produit aussi un 403 : le texte ne doit pas accuser le benevole. */
    public function testTheForbiddenPageAlsoExplainsAnExpiredForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_error/403');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
        self::assertSelectorTextContains('main', 'expiré');
    }

    public function testAnyOtherErrorGetsAPlainMessage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_error/500');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextContains('h1', 'Un problème est survenu');
        self::assertSelectorTextContains('main', 'Erreur 500');
    }
}
