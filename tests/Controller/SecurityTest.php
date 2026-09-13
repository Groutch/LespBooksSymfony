<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AdminWebTestCase;

class SecurityTest extends AdminWebTestCase
{
    public function testAdminCanLogInWithTheLoginForm(): void
    {
        $this->createAdmin();

        $crawler = $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@test.local',
            '_password' => 'motdepasse-de-test',
        ]));

        self::assertResponseRedirects('/admin');
    }

    /**
     * Quitter l'administration et se deconnecter aboutissent tous deux a l'accueil.
     * Le bandeau est le seul indice distinguant les deux etats : sans lui, un
     * benevole repart en croyant avoir ferme sa session. Il porte aussi le chemin
     * de retour, une pastille d'etat seule ne montrant pas par ou revenir.
     */
    public function testAdminBarAppearsOnThePublicSiteOnlyWhenLoggedIn(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Espace bénévoles');
        self::assertSelectorNotExists('a[href="/admin"]');
        self::assertSelectorNotExists('a[href="/deconnexion"]');

        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Connecté');
        self::assertSelectorTextContains('body', 'Admin');
        self::assertSelectorExists('a[href="/admin"]');
        self::assertSelectorExists('a[href="/deconnexion"]');
        // Le lien de connexion n'a plus de raison d'etre : le bandeau le remplace.
        self::assertSelectorTextNotContains('body', 'Espace bénévoles');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createAdmin();

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@test.local',
            '_password' => 'mauvais-mot-de-passe',
        ]));

        self::assertResponseRedirects('/connexion');
    }
}
