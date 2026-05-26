<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du sous-type de téléphone sur les demandes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD phone_type VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP phone_type');
    }
}
