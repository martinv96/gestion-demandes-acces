<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505085158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE login_audit (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(20) NOT NULL, email VARCHAR(180) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, firewall VARCHAR(100) DEFAULT NULL, occurred_at DATETIME NOT NULL, details LONGTEXT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_946838EDA76ED395 (user_id), INDEX idx_login_audit_occurred_at (occurred_at), INDEX idx_login_audit_event_type (event_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE login_audit ADD CONSTRAINT FK_946838EDA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE login_audit DROP FOREIGN KEY FK_946838EDA76ED395');
        $this->addSql('DROP TABLE login_audit');
    }
}
