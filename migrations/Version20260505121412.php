<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260505121412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE login_audit_daily_stat (id INT AUTO_INCREMENT NOT NULL, stat_date DATE NOT NULL, success_count INT DEFAULT 0 NOT NULL, failure_count INT DEFAULT 0 NOT NULL, logout_count INT DEFAULT 0 NOT NULL, purged_count INT DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_login_audit_daily_stat_date (stat_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE login_audit_daily_stat');
    }
}
