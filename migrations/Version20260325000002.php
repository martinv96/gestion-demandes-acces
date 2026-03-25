<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le statut d attribution des ressources';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ressource ADD assignment_status VARCHAR(30) NOT NULL DEFAULT 'non_attribue'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ressource DROP COLUMN assignment_status');
    }
}