<?php

/*
 * Écrit un dump SQL de la base sur la sortie standard.
 *
 * Exécuté SUR LE SERVEUR, mais sans y être livré : deploy/backup.sh l'envoie par
 * SSH sur l'entrée standard de PHP, depuis la racine du site.
 *
 *     ssh … "cd lesp && php" < deploy/dump.php | gzip > base.sql.gz
 *
 * Pourquoi pas une commande Symfony : elle n'existerait sur le serveur qu'une fois
 * livrée, dans la version livrée, et seulement après un cache:clear. Or la
 * sauvegarde passe AVANT la livraison : la première livraison qui l'apportait
 * aurait échoué sur sa propre sauvegarde, et un site cassé par une livraison
 * n'aurait plus pu être sauvegardé. Ce script ne dépend que de vendor/ et des
 * fichiers .env du serveur.
 *
 * Les identifiants sont lus dans les .env du serveur, comme Symfony les lit : ils
 * ne transitent jamais par la machine qui lance la sauvegarde.
 */

declare(strict_types=1);

use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;

$racine = (string) getcwd();
require $racine.'/vendor/autoload.php';

// Mêmes fichiers, même ordre que l'application (.env, .env.local, .env.prod…) ;
// une vraie variable d'environnement garde la priorité.
(new Dotenv())->bootEnv($racine.'/.env');

$url = $_SERVER['DATABASE_URL'] ?? '';

if (!\is_string($url) || '' === $url) {
    fwrite(\STDERR, "DATABASE_URL introuvable dans l'environnement ni dans les fichiers .env.\n");
    exit(1);
}

$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);

// Le mot de passe passe par un fichier d'options, jamais par un argument : la
// liste des processus d'un mutualisé se lit par tous ses comptes. MYSQL_PWD
// ferait l'affaire, mais MySQL l'a déclaré obsolète.
$identifiants = tempnam(sys_get_temp_dir(), 'lesp-dump-');

if (false === $identifiants) {
    fwrite(\STDERR, "Impossible de créer le fichier d'identifiants temporaire.\n");
    exit(1);
}

$code = 1;

try {
    chmod($identifiants, 0o600);
    file_put_contents($identifiants, \sprintf(
        "[client]\npassword=\"%s\"\n",
        addcslashes((string) ($params['password'] ?? ''), '\\"'),
    ));

    $commande = [
        'mysqldump',
        // Doit rester le premier argument, mysqldump l'exige.
        '--defaults-extra-file='.$identifiants,
        // Export cohérent sans verrouiller les tables pendant la lecture.
        '--single-transaction',
        // Un compte mutualisé n'a pas le droit PROCESS que réclame l'export des
        // tablespaces : sans cette option, MySQL 8 refuse le dump.
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--host='.($params['host'] ?? 'localhost'),
    ];

    if (isset($params['port'])) {
        $commande[] = '--port='.$params['port'];
    }

    $commande[] = '--user='.($params['user'] ?? '');
    $commande[] = (string) ($params['dbname'] ?? '');

    // mysqldump écrit directement sur la sortie de ce script : le dump ne
    // traverse pas PHP, quelle que soit sa taille.
    $processus = proc_open($commande, [0 => ['file', '/dev/null', 'r'], 1 => \STDOUT, 2 => \STDERR], $tubes);

    if (\is_resource($processus)) {
        $code = proc_close($processus);
    } else {
        fwrite(\STDERR, "Impossible de lancer mysqldump.\n");
    }
} finally {
    unlink($identifiants);
}

if (0 !== $code) {
    fwrite(\STDERR, \sprintf("mysqldump a échoué (code %d).\n", $code));
    exit(1);
}
