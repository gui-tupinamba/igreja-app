<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serializa a posição das imagens de cada publicação.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );
        $this->addSql(<<<'SQL'
            WITH ranked AS (
                SELECT id, row_number() OVER (PARTITION BY post_id ORDER BY position, id) - 1 AS new_position
                FROM post_images
            )
            UPDATE post_images p SET position = ranked.new_position
            FROM ranked WHERE ranked.id = p.id
            SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_post_images_post_position ON post_images (post_id, position)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.',
        );
        $this->addSql('DROP INDEX uniq_post_images_post_position');
    }
}
