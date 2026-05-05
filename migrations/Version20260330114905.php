<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260330114905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE request ADD parent_request_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F53D3CF5 FOREIGN KEY (parent_request_id) REFERENCES request (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3B978F9F53D3CF5 ON request (parent_request_id)');
        $this->addSql('ALTER TABLE ressource CHANGE assignment_status assignment_status VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE user CHANGE must_change_password must_change_password TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F53D3CF5');
        $this->addSql('DROP INDEX IDX_3B978F9F53D3CF5 ON request');
        $this->addSql('ALTER TABLE request DROP parent_request_id');
        $this->addSql('ALTER TABLE ressource CHANGE assignment_status assignment_status VARCHAR(30) DEFAULT \'non_attribue\' NOT NULL');
        $this->addSql('ALTER TABLE user CHANGE must_change_password must_change_password TINYINT DEFAULT 0 NOT NULL');
    }
}
