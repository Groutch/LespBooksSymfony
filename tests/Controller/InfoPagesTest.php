<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Tests\AdminWebTestCase;

/**
 * Infos pratiques, mentions legales, confidentialite. Les valeurs viennent de
 * `app.bibliotheque` (services.yaml) : ces tests verifient qu'elles arrivent
 * bien a l'ecran, et que ce que la page de confidentialite affirme reste vrai.
 */
class InfoPagesTest extends AdminWebTestCase
{
    public function testPracticalInfoGivesTheAddressOpeningHoursAndLoanDuration(): void
    {
        $this->client->request('GET', '/infos-pratiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Infos pratiques');
        self::assertSelectorTextContains('main address', '35 rue des Pyrénées');
        self::assertSelectorTextContains('main', 'Mardi');
        self::assertSelectorTextContains('main', '9 h – 11 h 30');
        self::assertSelectorTextContains('main', 'Jeudi');
        self::assertSelectorTextContains('main', '16 h – 18 h 30');
        // Tiree de app.default_loan_days : la page dit ce que la gestion propose.
        self::assertSelectorTextContains('main', '4 semaines');
    }

    public function testLegalNoticeNamesThePublisherThePublicationDirectorAndTheHost(): void
    {
        $this->client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Mairie de Lespouey');
        self::assertSelectorTextContains('main', 'Directeur de la publication');
        self::assertSelectorTextContains('main', 'Le maire de Lespouey');
        self::assertSelectorTextContains('main', 'OVH SAS');
        self::assertSelectorTextContains('main', '2 rue Kellermann, 59100 Roubaix');
    }

    public function testPrivacyPolicyTellsBorrowersWhatIsKeptAndWhereToComplain(): void
    {
        $this->client->request('GET', '/confidentialite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'votre prénom et votre nom');
        self::assertSelectorTextContains('main', 'aucun cookie');
        self::assertSelectorExists('main a[href="https://www.cnil.fr"]');
    }

    /** Ou et quand venir : sur l'accueil, et au pied de chaque page publique. */
    public function testOpeningHoursAndInformationLinksAreOnEveryPublicPage(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Ouverte le mardi de 9 h à 11 h 30 et le jeudi de 16 h à 18 h 30');

        $this->client->request('GET', '/catalogue');
        self::assertSelectorTextContains('footer', '35 rue des Pyrénées, 65190 Lespouey');
        self::assertSelectorTextContains('footer', 'le mardi de 9 h à 11 h 30');

        foreach (['/infos-pratiques', '/mentions-legales', '/confidentialite'] as $chemin) {
            self::assertSelectorExists(\sprintf('footer a[href="%s"]', $chemin));
        }
    }

    /**
     * La page de confidentialite l'affirme, et c'est ce qui dispense le site
     * d'un bandeau de consentement : un visiteur anonyme ne recoit aucun cookie.
     * Une session demarree par megarde sur une page publique casserait cela.
     */
    public function testAnonymousVisitorsReceiveNoCookie(): void
    {
        $book = (new Book())->setTitle('Dune');
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $pages = ['/', '/catalogue', '/catalogue?q=dune', '/livre/'.$book->getId(), '/infos-pratiques',
            '/mentions-legales', '/confidentialite', '/connexion'];

        foreach ($pages as $chemin) {
            $this->client->request('GET', $chemin);

            self::assertResponseIsSuccessful();
            self::assertSame([], $this->client->getResponse()->headers->getCookies(), $chemin);
        }
    }
}
