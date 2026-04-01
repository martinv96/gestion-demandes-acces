<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260401082438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE workflow_transition_config (id INT AUTO_INCREMENT NOT NULL, workflow_code VARCHAR(50) NOT NULL, step_order INT NOT NULL, action VARCHAR(20) NOT NULL, from_status VARCHAR(150) NOT NULL, to_status VARCHAR(150) NOT NULL, required_role VARCHAR(50) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql(<<<'SQL'
            INSERT INTO workflow_transition_config (workflow_code, step_order, action, from_status, to_status, required_role, is_active) VALUES
            ('default_access', 1, 'validate', 'en_attente_rh', 'en_attente_st', 'ROLE_RH', 1),
            ('default_access', 1, 'refuse', 'en_attente_rh', 'refusee_rh', 'ROLE_RH', 1),
            ('default_access', 2, 'validate', 'en_attente_st', 'en_attente_dsi', 'ROLE_ST', 1),
            ('default_access', 2, 'refuse', 'en_attente_st', 'en_attente_rh', 'ROLE_ST', 1),
            ('default_access', 3, 'validate', 'en_attente_dsi', 'traitee', 'ROLE_DSI', 1),
            ('default_access', 3, 'refuse', 'en_attente_dsi', 'en_attente_st', 'ROLE_DSI', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE workflow_transition_config');
    }


}
