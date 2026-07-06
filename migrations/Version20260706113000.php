<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le champ optionnel replacee_par pour les demandes Finance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD replacee_par VARCHAR(70) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP replacee_par');
    }
}
