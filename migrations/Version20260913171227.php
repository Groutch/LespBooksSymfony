<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une catégorie n'est plus qu'un mot-clé : un nom et son slug.
 *
 * Ces deux colonnes n'étaient alimentées que par l'écran d'administration des
 * catégories, supprimé en même temps. Le rangement physique est porté par
 * `Genre`, pas ici. Vérifié avant suppression : aucune ligne renseignée, ni en
 * développement ni en production.
 */
final class Version20260913171227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire category.description et category.shelf_code, devenues sans emploi.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP description, DROP shelf_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD description LONGTEXT DEFAULT NULL, ADD shelf_code VARCHAR(40) DEFAULT NULL');
    }
}
