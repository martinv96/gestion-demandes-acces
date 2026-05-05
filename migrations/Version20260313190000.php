<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prepare authentication and role management: unique email, larger password hash, unique role label';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE password password VARCHAR(255) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57698A6A33B92F39 ON role (label)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649E7927C74 ON user');
        $this->addSql('DROP INDEX UNIQ_57698A6A33B92F39 ON role');
        $this->addSql('ALTER TABLE user CHANGE password password VARCHAR(150) NOT NULL');
    }
}
