<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * deploy/dump.php est execute comme en production : envoye sur l'entree standard
 * de `php`, depuis la racine du projet. Le vrai mysqldump n'etant installe ni
 * sur l'hote ni dans le conteneur web, un faux, place en tete du PATH, recopie
 * ce qu'il recoit — de quoi verifier ce que le script lui transmet, et surtout
 * ce qu'il ne lui transmet pas.
 */
class DumpScriptTest extends TestCase
{
    private const string URL = 'mysql://lespouey:p%40ss%22word@db.example:3307/bibliotheque?serverVersion=8.0&charset=utf8mb4';

    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/dump-test-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dossier.'/*') ?: []);
        rmdir($this->dossier);
    }

    public function testThePasswordNeverAppearsOnTheCommandLine(): void
    {
        $sortie = $this->reussir();

        self::assertStringContainsString('ARG --host=db.example', $sortie);
        self::assertStringContainsString('ARG --port=3307', $sortie);
        self::assertStringContainsString('ARG --user=lespouey', $sortie);
        self::assertStringContainsString('ARG --single-transaction', $sortie);
        self::assertStringContainsString('ARG --no-tablespaces', $sortie);
        self::assertStringContainsString('ARG bibliotheque', $sortie);

        foreach (preg_grep('/^ARG /', explode("\n", $sortie)) ?: [] as $argument) {
            self::assertStringNotContainsString('p@ss', $argument);
        }

        // Guillemet et arobase decodes depuis l'URL, puis echappes pour mysqldump.
        self::assertStringContainsString('password="p@ss\"word"', $sortie);
    }

    public function testTheCredentialsFileIsDeletedOnceTheDumpIsDone(): void
    {
        $sortie = $this->reussir();

        self::assertSame(1, preg_match('/^FICHIER (.+)$/m', $sortie, $trouve));
        self::assertFileDoesNotExist($trouve[1]);
    }

    public function testTheDumpReachesStandardOutputUntouched(): void
    {
        $sortie = $this->reussir();

        self::assertStringEndsWith("<info>pas une balise</info>\n-- Dump completed\n", $sortie);
    }

    public function testAFailedDumpIsReportedAsAFailure(): void
    {
        file_put_contents($this->dossier.'/mysqldump', "#!/bin/sh\necho 'Access denied' >&2\nexit 2\n");
        chmod($this->dossier.'/mysqldump', 0o755);

        $process = $this->executer();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Access denied', $process->getErrorOutput());
        self::assertStringContainsString('mysqldump a échoué (code 2)', $process->getErrorOutput());
        self::assertSame([], glob(sys_get_temp_dir().'/lesp-dump-*') ?: []);
    }

    private function reussir(): string
    {
        $this->fauxMysqldump();
        $process = $this->executer();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process->getOutput();
    }

    private function executer(): Process
    {
        $racine = \dirname(__DIR__, 2);

        // Une vraie variable d'environnement prime sur les fichiers .env, comme
        // sur le serveur : c'est elle que le script doit lire. SYMFONY_DOTENV_VARS,
        // pose par le bootstrap des tests et transmis par Process, ferait croire au
        // Dotenv du script que DATABASE_URL vient d'un fichier, donc remplacable.
        $process = new Process(['php'], $racine, [
            'DATABASE_URL' => self::URL,
            'SYMFONY_DOTENV_VARS' => false,
            'PATH' => $this->dossier.':'.getenv('PATH'),
        ]);
        $process->setInput((string) file_get_contents($racine.'/deploy/dump.php'));
        $process->run();

        return $process;
    }

    private function fauxMysqldump(): void
    {
        $binaire = $this->dossier.'/mysqldump';
        file_put_contents($binaire, <<<'SH'
            #!/bin/sh
            for argument in "$@"; do printf 'ARG %s\n' "$argument"; done
            case "$1" in
                --defaults-extra-file=*)
                    fichier="${1#--defaults-extra-file=}"
                    cat "$fichier"
                    printf 'FICHIER %s\n' "$fichier"
                    ;;
            esac
            printf '<info>pas une balise</info>\n'
            printf -- '-- Dump completed\n'
            SH);
        chmod($binaire, 0o755);
    }
}
