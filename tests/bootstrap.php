<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Un shell lance depuis l'editeur peut exporter les valeurs du .env, dont un
// APP_SECRET vide. Une vraie variable d'environnement primant sur les fichiers,
// il ecrasait celui de .env.test, et « Rester connecte » faisait echouer presque
// toute la suite en 500 (« A non-empty secret is required »). Vide, il est donc
// tenu pour absent ; renseigne, il garde la priorite.
if ('' === getenv('APP_SECRET')) {
    putenv('APP_SECRET');
    unset($_SERVER['APP_SECRET'], $_ENV['APP_SECRET']);
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
