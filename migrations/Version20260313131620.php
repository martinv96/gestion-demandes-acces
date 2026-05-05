<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260313131620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE request_ressource (request_id INT NOT NULL, ressource_id INT NOT NULL, INDEX IDX_D86EB4E3427EB8A5 (request_id), INDEX IDX_D86EB4E3FC6CD52A (ressource_id), PRIMARY KEY (request_id, ressource_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE request_ressource ADD CONSTRAINT FK_D86EB4E3427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE request_ressource ADD CONSTRAINT FK_D86EB4E3FC6CD52A FOREIGN KEY (ressource_id) REFERENCES ressource (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY `FK_3B978F9F3C361826`');
        $this->addSql('DROP INDEX IDX_3B978F9F3C361826 ON request');
        $this->addSql('ALTER TABLE request DROP ressources_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE request_ressource DROP FOREIGN KEY FK_D86EB4E3427EB8A5');
        $this->addSql('ALTER TABLE request_ressource DROP FOREIGN KEY FK_D86EB4E3FC6CD52A');
        $this->addSql('DROP TABLE request_ressource');
        $this->addSql('ALTER TABLE request ADD ressources_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT `FK_3B978F9F3C361826` FOREIGN KEY (ressources_id) REFERENCES ressource (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_3B978F9F3C361826 ON request (ressources_id)');
    }
}
