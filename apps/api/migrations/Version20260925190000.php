<?php

  declare(strict_types=1);

  namespace DoctrineMigrations;

  use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
  use Doctrine\DBAL\Schema\Schema;
  use Doctrine\Migrations\AbstractMigration;

  final class Version20260925190000 extends AbstractMigration
  {
      public function getDescription(): string
      {
          return 'Adiciona revisão de Pastor/Admin para eventos e atividades.';
      }

      public function up(
          Schema $schema,
      ): void {
          $this->abortIf(
              !$this->connection
                  ->getDatabasePlatform()
                  instanceof PostgreSQLPlatform,
              'This migration requires PostgreSQL.',
          );

          $this->addSql(
              'ALTER TABLE events
              DROP CONSTRAINT chk_events_status'
          );

          $this->addSql(
              "ALTER TABLE events
              ADD CONSTRAINT chk_events_status
              CHECK (
                  status IN (
                      'DRAFT',
                      'PENDING_REVIEW',
                      'PUBLISHED',
                      'CANCELLED',
                      'ARCHIVED'
                  )
              )"
          );

          $this->addSql(
              'ALTER TABLE ministry_schedules
              DROP CONSTRAINT chk_ministry_schedules_status'
          );

          $this->addSql(
              "ALTER TABLE ministry_schedules
              ADD CONSTRAINT chk_ministry_schedules_status
              CHECK (
                  status IN (
                      'DRAFT',
                      'PENDING_REVIEW',
                      'PUBLISHED',
                      'CANCELLED',
                      'ARCHIVED'
                  )
              )"
          );
      }

      public function down(
          Schema $schema,
      ): void {
          $this->abortIf(
              !$this->connection
                  ->getDatabasePlatform()
                  instanceof PostgreSQLPlatform,
              'This migration requires PostgreSQL.',
          );

          $this->addSql(
              "UPDATE events
              SET status = 'DRAFT'
              WHERE status = 'PENDING_REVIEW'"
          );

          $this->addSql(
              "UPDATE ministry_schedules
              SET status = 'DRAFT'
              WHERE status = 'PENDING_REVIEW'"
          );

          $this->addSql(
              'ALTER TABLE events
              DROP CONSTRAINT chk_events_status'
          );

          $this->addSql(
              "ALTER TABLE events
              ADD CONSTRAINT chk_events_status
              CHECK (
                  status IN (
                      'DRAFT',
                      'PUBLISHED',
                      'CANCELLED',
                      'ARCHIVED'
                  )
              )"
          );

          $this->addSql(
              'ALTER TABLE ministry_schedules
              DROP CONSTRAINT chk_ministry_schedules_status'
          );

          $this->addSql(
              "ALTER TABLE ministry_schedules
              ADD CONSTRAINT chk_ministry_schedules_status
              CHECK (
                  status IN (
                      'DRAFT',
                      'PUBLISHED',
                      'CANCELLED',
                      'ARCHIVED'
                  )
              )"
          );
      }
  }