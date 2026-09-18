<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona estado de revisão às publicações.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.'
        );

        $this->addSql(
            'ALTER TABLE posts
             DROP CONSTRAINT chk_posts_status'
        );

        $this->addSql(
            "ALTER TABLE posts
             ADD CONSTRAINT chk_posts_status
             CHECK (
                 status IN (
                     'DRAFT',
                     'PENDING_REVIEW',
                     'PUBLISHED',
                     'ARCHIVED'
                 )
             )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration requires PostgreSQL.'
        );

        $this->addSql(
            "UPDATE posts
              SET status = 'DRAFT'
              WHERE status = 'PENDING_REVIEW'"
        );

        $this->addSql(
            'ALTER TABLE posts
              DROP CONSTRAINT chk_posts_status'
        );

        $this->addSql(
            "ALTER TABLE posts
              ADD CONSTRAINT chk_posts_status
              CHECK (
                  status IN (
                      'DRAFT',
                      'PUBLISHED',
                      'ARCHIVED'
                  )
              )"
        );
    }
}