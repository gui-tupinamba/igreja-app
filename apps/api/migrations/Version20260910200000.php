<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authentication sessions, hashed rotating refresh tokens, durable login limits and immediate credential/status revocation.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_sessions (
                id VARCHAR(32) NOT NULL,
                user_id INT NOT NULL,
                client_type VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_used_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_auth_sessions_user_revoked ON auth_sessions (user_id, revoked_at)');
        $this->addSql('CREATE INDEX idx_auth_sessions_expires ON auth_sessions (expires_at)');
        $this->addSql('ALTER TABLE auth_sessions ADD CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("ALTER TABLE auth_sessions ADD CONSTRAINT chk_auth_sessions_id CHECK (id ~ '^[a-f0-9]{32}$')");
        $this->addSql("ALTER TABLE auth_sessions ADD CONSTRAINT chk_auth_sessions_client CHECK (client_type IN ('WEB', 'MOBILE'))");
        $this->addSql('ALTER TABLE auth_sessions ADD CONSTRAINT chk_auth_sessions_dates CHECK (expires_at > created_at AND last_used_at >= created_at AND (revoked_at IS NULL OR revoked_at >= created_at))');

        $this->addSql(<<<'SQL'
            CREATE TABLE refresh_tokens (
                id VARCHAR(32) NOT NULL,
                session_id VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                replaced_by_id VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_tokens_hash ON refresh_tokens (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_tokens_replacement ON refresh_tokens (replaced_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_tokens_current_session ON refresh_tokens (session_id) WHERE ((consumed_at IS NULL) AND (revoked_at IS NULL))');
        $this->addSql('CREATE INDEX idx_refresh_tokens_session ON refresh_tokens (session_id)');
        $this->addSql('CREATE INDEX idx_refresh_tokens_expires ON refresh_tokens (expires_at)');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT fk_refresh_tokens_session FOREIGN KEY (session_id) REFERENCES auth_sessions (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT fk_refresh_tokens_replacement FOREIGN KEY (replaced_by_id) REFERENCES refresh_tokens (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("ALTER TABLE refresh_tokens ADD CONSTRAINT chk_refresh_tokens_id CHECK (id ~ '^[a-f0-9]{32}$')");
        $this->addSql("ALTER TABLE refresh_tokens ADD CONSTRAINT chk_refresh_tokens_hash CHECK (token_hash ~ '^[a-f0-9]{64}$')");
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT chk_refresh_tokens_dates CHECK (expires_at > created_at AND (consumed_at IS NULL OR consumed_at >= created_at) AND (revoked_at IS NULL OR revoked_at >= created_at))');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT chk_refresh_tokens_replacement CHECK (replaced_by_id IS NULL OR (consumed_at IS NOT NULL AND replaced_by_id <> id))');

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_login_limits (
                id VARCHAR(64) NOT NULL,
                attempts INT NOT NULL,
                window_started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_auth_login_limits_window ON auth_login_limits (window_started_at)');
        $this->addSql("ALTER TABLE auth_login_limits ADD CONSTRAINT chk_auth_login_limits_id CHECK (id ~ '^[a-f0-9]{64}$')");
        $this->addSql('ALTER TABLE auth_login_limits ADD CONSTRAINT chk_auth_login_limits_attempts CHECK (attempts > 0)');
        $this->addSql('ALTER TABLE auth_login_limits ADD CONSTRAINT chk_auth_login_limits_window CHECK (mod(extract(epoch FROM window_started_at), 900) = 0)');

        // This protects every mutation path (Doctrine, console and direct SQL),
        // using the row lock PostgreSQL already took on the updated user.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION auth_revoke_user_sessions() RETURNS trigger
            LANGUAGE plpgsql AS $function$
            DECLARE
                revoked_time timestamp with time zone := date_trunc('second', clock_timestamp());
            BEGIN
                IF OLD.password_hash IS DISTINCT FROM NEW.password_hash
                   OR (OLD.status IS DISTINCT FROM NEW.status AND NEW.status <> 'ACTIVE') THEN
                    UPDATE auth_sessions
                    SET revoked_at = COALESCE(revoked_at, revoked_time)
                    WHERE user_id = NEW.id;
                    UPDATE refresh_tokens
                    SET revoked_at = COALESCE(revoked_at, revoked_time)
                    WHERE session_id IN (SELECT id FROM auth_sessions WHERE user_id = NEW.id);
                END IF;
                RETURN NEW;
            END;
            $function$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_users_auth_revoke
            AFTER UPDATE OF password_hash, status ON users
            FOR EACH ROW EXECUTE FUNCTION auth_revoke_user_sessions()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql('DROP TRIGGER trg_users_auth_revoke ON users');
        $this->addSql('DROP FUNCTION auth_revoke_user_sessions()');
        $this->addSql('DROP TABLE auth_login_limits');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE auth_sessions');
    }
}
