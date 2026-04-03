<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260313151700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Backfill minimal avant passage en NOT NULL
        $userCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user');
        $requestCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM request');
        $workflowCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM workflow_history');

        $this->abortIf($requestCount > 0 && $userCount === 0, 'Impossible de rendre request.author_id NOT NULL: aucun utilisateur en base.');
        $this->abortIf($workflowCount > 0 && ($userCount === 0 || $requestCount === 0), 'Impossible de rendre workflow_history.request_id/user_id NOT NULL: donnees de reference manquantes.');

        if ($userCount > 0) {
            $this->addSql('UPDATE request SET author_id = (SELECT id FROM user ORDER BY id ASC LIMIT 1) WHERE author_id IS NULL');
            $this->addSql('UPDATE workflow_history SET user_id = (SELECT id FROM user ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL');
        }

        if ($requestCount > 0) {
            $this->addSql('UPDATE workflow_history SET request_id = (SELECT id FROM request ORDER BY id ASC LIMIT 1) WHERE request_id IS NULL');
        }

        // Il faut supprimer les FKs avant de modifier la nullabilite des colonnes ciblees
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F3414710B');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9FF675F31B');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FB427EB8A5');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FBA76ED395');

        $this->addSql('ALTER TABLE request CHANGE commentary commentary LONGTEXT DEFAULT NULL, CHANGE agent_id agent_id INT NOT NULL, CHANGE author_id author_id INT NOT NULL');
        // $this->addSql('ALTER TABLE role RENAME INDEX uniq_57698a6a33b92f39 TO UNIQ_57698A6AEA750E8');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E19D9AD25E237E06 ON service (name)');
        $this->addSql('ALTER TABLE workflow_history CHANGE commentary commentary LONGTEXT NOT NULL, CHANGE request_id request_id INT NOT NULL, CHANGE user_id user_id INT NOT NULL');

        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F3414710B FOREIGN KEY (agent_id) REFERENCES agent (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9FF675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FB427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F3414710B');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9FF675F31B');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FB427EB8A5');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FBA76ED395');

        $this->addSql('ALTER TABLE request CHANGE commentary commentary VARCHAR(255) NOT NULL, CHANGE agent_id agent_id INT DEFAULT NULL, CHANGE author_id author_id INT DEFAULT NULL');
        // $this->addSql('ALTER TABLE role RENAME INDEX uniq_57698a6aea750e8 TO UNIQ_57698A6A33B92F39');
        $this->addSql('DROP INDEX UNIQ_E19D9AD25E237E06 ON service');
        $this->addSql('ALTER TABLE workflow_history CHANGE commentary commentary VARCHAR(255) NOT NULL, CHANGE request_id request_id INT DEFAULT NULL, CHANGE user_id user_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F3414710B FOREIGN KEY (agent_id) REFERENCES agent (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9FF675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FB427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }
}
