<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des EPI dans les ressources matériel (service technique)';
    }

    public function up(Schema $schema): void
    {
        $items = [
            'Casque de chantier',
            'Gilet haute visibilité',
            'Chaussures de sécurité',
            'Pantalon de travail',
            'Veste de travail',
            'Gants de protection',
            'Lunettes de protection',
            'Masque de protection',
        ];

        foreach ($items as $name) {
            $this->addSql(
                'INSERT INTO ressource (name, category, is_active, assignment_status) '
                . 'SELECT ?, ?, 1, ? FROM DUAL WHERE NOT EXISTS ('
                . 'SELECT 1 FROM ressource r WHERE r.name = ? AND r.category = ?'
                . ')',
                [$name, 'materiel', 'non_attribue', $name, 'materiel']
            );
        }
    }

    public function down(Schema $schema): void
    {
        $items = [
            'Casque de chantier',
            'Gilet haute visibilité',
            'Chaussures de sécurité',
            'Pantalon de travail',
            'Veste de travail',
            'Gants de protection',
            'Lunettes de protection',
            'Masque de protection',
        ];

        foreach ($items as $name) {
            $this->addSql(
                'DELETE FROM ressource WHERE name = ? AND category = ?',
                [$name, 'materiel']
            );
        }
    }
}
