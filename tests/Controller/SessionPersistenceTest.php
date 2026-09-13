<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AdminWebTestCase;

class SessionPersistenceTest extends AdminWebTestCase
{
    /**
     * Sans ce cookie, toute expiration de session renvoie le bénévole au
     * formulaire de connexion — sur un téléphone, avec un mot de passe à ressaisir.
     */
    public function testLoginIssuesARememberMeCookieValidForAWeek(): void
    {
        $this->createAdmin();

        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'admin@test.local',
            '_password' => 'motdepasse-de-test',
            '_remember_me' => 'on',
        ]));

        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        self::assertArrayHasKey('REMEMBERME', $cookies, 'Aucun cookie de persistance n\'est posé.');

        $remember = $cookies['REMEMBERME'];
        self::assertTrue($remember->isHttpOnly(), 'Le cookie doit rester hors de portée du JavaScript.');
        self::assertSame('/', $remember->getPath(), 'Il doit couvrir le site entier, pas seulement /admin.');
        // 7 jours, a quelques secondes pres : la valeur vient de security.yaml.
        self::assertEqualsWithDelta(time() + 604800, $remember->getExpiresTime(), 60);
    }
}
