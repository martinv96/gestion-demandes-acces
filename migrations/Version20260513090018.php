<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513090018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des tailles vêtements et chaussures sur agent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent ADD clothing_size VARCHAR(20) DEFAULT NULL, ADD shoe_size VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent DROP clothing_size, DROP shoe_size');
    }
}
