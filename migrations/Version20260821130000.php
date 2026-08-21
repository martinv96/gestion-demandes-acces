<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronise le nom de l index unique de la table role avec le mapping Doctrine';
    }

    public function up(Schema $schema): void
    {
        $indexes = array_map('strtolower', array_keys($this->connection->createSchemaManager()->introspectTableIndexesByUnquotedName('role')));

        if (in_array('uniq_57698a6a33b92f39', $indexes, true) && !in_array('uniq_57698a6aea750e8', $indexes, true)) {
            $this->addSql('ALTER TABLE role RENAME INDEX uniq_57698a6a33b92f39 TO UNIQ_57698A6AEA750E8');
        }
    }

    public function down(Schema $schema): void
    {
        $indexes = array_map('strtolower', array_keys($this->connection->createSchemaManager()->introspectTableIndexesByUnquotedName('role')));

        if (in_array('uniq_57698a6aea750e8', $indexes, true) && !in_array('uniq_57698a6a33b92f39', $indexes, true)) {
            $this->addSql('ALTER TABLE role RENAME INDEX UNIQ_57698A6AEA750E8 TO uniq_57698a6a33b92f39');
        }
    }
}