<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute must_change_password à la table user (changement obligatoire au 1er login)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD must_change_password TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN must_change_password');
    }
}
