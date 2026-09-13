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
     * La pastille est le seul indice permettant de distinguer les deux etats, et
     * evite qu'un benevole reparte en croyant avoir ferme sa session.
     */
    public function testPublicHeaderShowsTheSessionBadgeOnlyWhenLoggedIn(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', 'Espace bénévoles');
        self::assertSelectorNotExists('header a[href="/admin"]');

        $this->client->loginUser($this->createAdmin());
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('header', 'Connecté');
        self::assertSelectorExists('header a[href="/admin"]');
        self::assertSelectorTextNotContains('header', 'Espace bénévoles');
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
