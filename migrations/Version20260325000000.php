<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le champ code au service pour les permissions workflow (RH, ST, DSI)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service ADD code VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service DROP COLUMN code');
    }
}
