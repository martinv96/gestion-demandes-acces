<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821120855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE private_comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, target_service VARCHAR(50) DEFAULT NULL, request_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_658D9E88427EB8A5 (request_id), INDEX IDX_658D9E88F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE private_comment ADD CONSTRAINT FK_658D9E88427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE private_comment ADD CONSTRAINT FK_658D9E88F675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE private_comment DROP FOREIGN KEY FK_658D9E88427EB8A5');
        $this->addSql('ALTER TABLE private_comment DROP FOREIGN KEY FK_658D9E88F675F31B');
        $this->addSql('DROP TABLE private_comment');
    }
}
