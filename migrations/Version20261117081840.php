<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117081840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the speech table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE speech (id BLOB NOT NULL, title VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, recording_filename VARCHAR(64) NOT NULL, recording_mime_type VARCHAR(32) NOT NULL, duration_seconds DOUBLE PRECISION DEFAULT NULL, transcript CLOB DEFAULT NULL, fillers CLOB DEFAULT NULL, coaching CLOB DEFAULT NULL, error CLOB DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX speech_created_at_idx ON speech (created_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE speech');
    }
}
