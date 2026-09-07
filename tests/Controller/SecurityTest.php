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
