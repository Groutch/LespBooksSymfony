<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AdminWebTestCase;

/**
 * L'administration est le vrai point d'entrée des bénévoles : elle s'installe
 * sur l'écran d'accueil. Ces fichiers sont servis statiquement par Apache, pas
 * par Symfony, et ne passent pas par AssetMapper — un service worker doit vivre
 * à une URL stable, sinon chaque livraison en installerait un nouveau.
 */
class PwaTest extends AdminWebTestCase
{
    public function testAdminPagesDeclareTheManifest(): void
    {
        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="manifest"][href="/pwa/manifest.webmanifest"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"]');
        self::assertSelectorExists('meta[name="theme-color"][content="#8c3a2b"]');
    }

    /** Le site vitrine n'est pas l'application : il ne doit rien déclarer. */
    public function testThePublicSiteDoesNotDeclareTheManifest(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('link[rel="manifest"]');
        self::assertSelectorExists('meta[name="theme-color"][content="#f7f3ec"]');
    }

    public function testTheManifestIsValidAndPointsToExistingIcons(): void
    {
        $chemin = \dirname(__DIR__, 2).'/public/pwa/manifest.webmanifest';
        self::assertFileExists($chemin);

        $manifest = json_decode((string) file_get_contents($chemin), true, flags: \JSON_THROW_ON_ERROR);

        // Ce que Chrome exige pour proposer l'installation.
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/admin', $manifest['scope']);
        self::assertStringStartsWith('/admin', $manifest['start_url']);

        $tailles = array_column($manifest['icons'], 'sizes');
        self::assertContains('192x192', $tailles);
        self::assertContains('512x512', $tailles);
        self::assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $icone) {
            self::assertFileExists(\dirname(__DIR__, 2).'/public'.$icone['src']);
        }
    }

    /**
     * Chrome n'installe pas une application dont le service worker n'intercepte
     * rien : le gestionnaire `fetch` est la condition, même sans mise en cache.
     */
    public function testTheServiceWorkerHasAFetchHandlerAndCachesNothing(): void
    {
        $chemin = \dirname(__DIR__, 2).'/public/sw.js';
        self::assertFileExists($chemin);

        $source = (string) file_get_contents($chemin);

        self::assertStringContainsString("addEventListener('fetch'", $source);
        self::assertStringNotContainsString('caches.open', $source);
    }
}
