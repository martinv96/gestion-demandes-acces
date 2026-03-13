<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260313104524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agent (id INT AUTO_INCREMENT NOT NULL, civility VARCHAR(100) NOT NULL, firstname VARCHAR(100) NOT NULL, lastname VARCHAR(100) NOT NULL, job_title VARCHAR(100) NOT NULL, service_id INT DEFAULT NULL, INDEX IDX_268B9C9DED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(150) NOT NULL, status VARCHAR(150) NOT NULL, arrival_date DATE NOT NULL, departure_date DATE DEFAULT NULL, commentary VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, update_date DATETIME NOT NULL, agent_id INT DEFAULT NULL, author_id INT DEFAULT NULL, ressources_id INT DEFAULT NULL, INDEX IDX_3B978F9F3414710B (agent_id), INDEX IDX_3B978F9FF675F31B (author_id), INDEX IDX_3B978F9F3C361826 (ressources_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ressource (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, category VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, email VARCHAR(100) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, firstname VARCHAR(100) NOT NULL, lastname VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL, password VARCHAR(150) NOT NULL, is_active TINYINT NOT NULL, role_id INT DEFAULT NULL, service_id INT DEFAULT NULL, INDEX IDX_8D93D649D60322AC (role_id), INDEX IDX_8D93D649ED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workflow_history (id INT AUTO_INCREMENT NOT NULL, old_status VARCHAR(255) NOT NULL, new_status VARCHAR(255) NOT NULL, commentary VARCHAR(255) NOT NULL, date DATETIME NOT NULL, request_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX IDX_25F6E6FB427EB8A5 (request_id), INDEX IDX_25F6E6FBA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agent ADD CONSTRAINT FK_268B9C9DED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F3414710B FOREIGN KEY (agent_id) REFERENCES agent (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9FF675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F3C361826 FOREIGN KEY (ressources_id) REFERENCES ressource (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649D60322AC FOREIGN KEY (role_id) REFERENCES role (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649ED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FB427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE workflow_history ADD CONSTRAINT FK_25F6E6FBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent DROP FOREIGN KEY FK_268B9C9DED5CA9E6');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F3414710B');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9FF675F31B');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F3C361826');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649D60322AC');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649ED5CA9E6');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FB427EB8A5');
        $this->addSql('ALTER TABLE workflow_history DROP FOREIGN KEY FK_25F6E6FBA76ED395');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE request');
        $this->addSql('DROP TABLE ressource');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE workflow_history');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
