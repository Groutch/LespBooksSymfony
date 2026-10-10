<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Tests\AdminWebTestCase;

/**
 * Ce qu'un moteur de recherche ou l'apercu d'un lien partage lit d'une page :
 * titre, description, image, consignes d'indexation. Rien de cela ne s'affiche
 * a l'ecran, si bien qu'une regression passerait inapercue sans ces tests.
 */
class PageMetadataTest extends AdminWebTestCase
{
    public function testThePageTitleNamesThePageThenTheSite(): void
    {
        $crawler = $this->client->request('GET', '/catalogue');
        self::assertSame('Rechercher un livre · Bibliothèque de Lespouey', $crawler->filter('title')->text());

        // L'accueil n'a pas d'autre nom que celui du site : pas de redite.
        $crawler = $this->client->request('GET', '/');
        self::assertSame('Bibliothèque de Lespouey', $crawler->filter('title')->text());
    }

    public function testPublicPagesHaveADescriptionAndAnAbsoluteSharingImage(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        // Les reseaux ignorent une image en chemin relatif.
        self::assertMatchesRegularExpression(
            '#^https?://[^/]+/assets/images/partage-[^/]+\.png$#',
            (string) $crawler->filter('meta[property="og:image"]')->attr('content'),
        );
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
    }

    /**
     * Titre et description viennent des sources externes : apostrophes,
     * esperluettes et retours a la ligne doivent sortir propres, et echappes
     * une seule fois.
     */
    public function testABookPageIsDescribedByItsOwnContent(): void
    {
        $book = (new Book())
            ->setTitle("L'Écume des jours & autres")
            ->setDescription("Colin aime Chloé.\n\nMais un nénuphar pousse dans sa poitrine, "
                .str_repeat('et la maison rétrécit, ', 10));
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/livre/'.$book->getId());

        self::assertSame("L'Écume des jours & autres · Bibliothèque de Lespouey", $crawler->filter('title')->text());
        self::assertSame("L'Écume des jours & autres", $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('book', $crawler->filter('meta[property="og:type"]')->attr('content'));

        $resume = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringStartsWith('Colin aime Chloé. Mais un nénuphar', $resume);
        self::assertLessThanOrEqual(160, mb_strlen($resume));
        self::assertStringEndsWith('…', $resume);
    }

    public function testABookWithoutDescriptionStillGetsOne(): void
    {
        $auteur = (new Author())->setName('Émile Zola')->setSlug('emile-zola');
        $book = (new Book())->setTitle('Germinal')->addAuthor($auteur);
        $this->entityManager->persist($auteur);
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/livre/'.$book->getId());

        self::assertSame(
            '« Germinal », Émile Zola : à emprunter à la bibliothèque communautaire de Lespouey.',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    public function testTheVolunteerAreaIsKeptOutOfSearchEngines(): void
    {
        $this->client->request('GET', '/connexion');
        self::assertSelectorExists('meta[name="robots"][content*="noindex"]');

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin');
        self::assertSelectorExists('meta[name="robots"][content*="noindex"]');

        $this->client->request('GET', '/catalogue');
        self::assertSelectorNotExists('meta[name="robots"]');

        $robots = (string) file_get_contents(\dirname(__DIR__, 2).'/public/robots.txt');
        self::assertStringContainsString("Disallow: /admin\n", $robots);
        self::assertStringContainsString("Disallow: /connexion\n", $robots);
    }

    public function testTheSiteIconIsDeclaredAndPresent(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('link[rel="icon"][href="/favicon.ico"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"]');
        self::assertFileExists(\dirname(__DIR__, 2).'/public/favicon.ico');
    }
}
