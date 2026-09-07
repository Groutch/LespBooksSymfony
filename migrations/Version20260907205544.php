<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « primary » est un mot réservé MySQL que Doctrine échappe au CREATE TABLE
 * mais pas dans les INSERT : toute couverture rendait l'enregistrement impossible.
 */
final class Version20260907205544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomme book_image.primary en is_primary (mot réservé MySQL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_image CHANGE `primary` is_primary TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_image CHANGE is_primary `primary` TINYINT NOT NULL');
    }
}
