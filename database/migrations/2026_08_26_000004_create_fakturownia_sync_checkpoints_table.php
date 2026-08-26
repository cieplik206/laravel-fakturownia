<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fakturownia_sync_checkpoints', function (Blueprint $table): void {
            $table->string('connection_key', 128);
            $table->string('lane', 64);
            $table->timestampTz('cursor_updated_at', precision: 6)->nullable();
            $table->string('cursor_remote_id', 191)->nullable();
            $table->unsignedInteger('lease_generation')->default(0);
            $table->char('lease_token_sha256', 64)->nullable();
            $table->timestampTz('lease_acquired_at', precision: 6)->nullable();
            $table->timestampTz('lease_expires_at', precision: 6)->nullable();
            $table->timestampTz('updated_at', precision: 6);

            $table->primary(['connection_key', 'lane'], 'fakturownia_sync_checkpoints_primary');
            $table->index(['lease_expires_at'], 'fakturownia_sync_checkpoints_lease_expiry_idx');
            $table->index(
                ['connection_key', 'lane', 'cursor_updated_at', 'cursor_remote_id'],
                'fakturownia_sync_checkpoints_cursor_idx',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $schema = $this->postgresSchema($connection);
            $table = $this->qualifiedIdentifier($schema, 'fakturownia_sync_checkpoints');
            $rowFunction = $this->qualifiedIdentifier($schema, 'fakturownia_guard_sync_checkpoint_transition');
            $truncateFunction = $this->qualifiedIdentifier($schema, 'fakturownia_guard_sync_checkpoint_truncate');

            $connection->statement("DROP TABLE IF EXISTS {$table}");
            $connection->statement("DROP FUNCTION IF EXISTS {$rowFunction}()");
            $connection->statement("DROP FUNCTION IF EXISTS {$truncateFunction}()");

            return;
        }

        Schema::dropIfExists('fakturownia_sync_checkpoints');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia sync checkpoint database connection must be a string or null.');
        }

        return $connection;
    }

    private function addPostgresConstraintsAndGuards(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = $this->postgresSchema($connection);
        $replacements = [
            '{{table}}' => $this->qualifiedIdentifier($schema, 'fakturownia_sync_checkpoints'),
            '{{row_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_sync_checkpoint_transition'),
            '{{truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_sync_checkpoint_truncate'),
        ];

        foreach ([
            <<<'SQL'
                ALTER TABLE {{table}}
                ADD CONSTRAINT fakturownia_sync_checkpoints_identity_check CHECK (
                    connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND lane ~ '^[a-z][a-z0-9._-]{0,63}$'
                ),
                ADD CONSTRAINT fakturownia_sync_checkpoints_cursor_check CHECK (
                    (cursor_updated_at IS NULL) = (cursor_remote_id IS NULL)
                    AND (cursor_remote_id IS NULL OR (
                        octet_length(cursor_remote_id) BETWEEN 1 AND 191
                        AND cursor_remote_id !~ '[[:cntrl:]]'
                    ))
                ),
                ADD CONSTRAINT fakturownia_sync_checkpoints_lease_check CHECK (
                    lease_generation BETWEEN 0 AND 2147483647
                    AND (
                        (lease_token_sha256 IS NULL AND lease_acquired_at IS NULL AND lease_expires_at IS NULL)
                        OR (
                            lease_generation > 0
                            AND lease_token_sha256 ~ '^[a-f0-9]{64}$'
                            AND lease_acquired_at IS NOT NULL
                            AND lease_expires_at IS NOT NULL
                            AND lease_acquired_at < lease_expires_at
                        )
                    )
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{row_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoints cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.lane IS DISTINCT FROM OLD.lane THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint identity is immutable' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.cursor_updated_at IS NOT NULL AND (
                        NEW.cursor_updated_at IS NULL
                        OR ROW(NEW.cursor_updated_at, NEW.cursor_remote_id)
                            < ROW(OLD.cursor_updated_at, OLD.cursor_remote_id)
                    ) THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint cursor cannot regress' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.lease_generation < OLD.lease_generation
                        OR NEW.lease_generation > OLD.lease_generation + 1 THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint lease generation transition is invalid' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.lease_generation = OLD.lease_generation AND (
                        NEW.lease_token_sha256 IS DISTINCT FROM OLD.lease_token_sha256
                        OR NEW.lease_acquired_at IS DISTINCT FROM OLD.lease_acquired_at
                        OR NEW.lease_expires_at IS DISTINCT FROM OLD.lease_expires_at
                    ) AND NOT (
                        OLD.lease_token_sha256 IS NOT NULL
                        AND NEW.lease_token_sha256 IS NULL
                        AND NEW.lease_acquired_at IS NULL
                        AND NEW.lease_expires_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint lease cannot be replaced without fencing' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.lease_generation = OLD.lease_generation + 1 AND (
                        NEW.lease_token_sha256 IS NULL
                        OR NEW.lease_acquired_at IS NULL
                        OR NEW.lease_expires_at IS NULL
                        OR NEW.lease_token_sha256 IS NOT DISTINCT FROM OLD.lease_token_sha256
                        OR NEW.cursor_updated_at IS DISTINCT FROM OLD.cursor_updated_at
                        OR NEW.cursor_remote_id IS DISTINCT FROM OLD.cursor_remote_id
                    ) THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint fenced lease acquisition is invalid' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.updated_at < OLD.updated_at THEN
                        RAISE EXCEPTION 'Fakturownia sync checkpoint update time cannot regress' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_sync_checkpoints_transition_guard
                BEFORE UPDATE OR DELETE ON {{table}}
                FOR EACH ROW EXECUTE FUNCTION {{row_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia sync checkpoints cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_sync_checkpoints_no_truncate
                BEFORE TRUNCATE ON {{table}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{truncate_function}}()
                SQL,
        ] as $statement) {
            $connection->statement(strtr($statement, $replacements));
        }
    }

    private function postgresSchema(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT current_schema() AS schema_name');
        $schema = $row instanceof stdClass ? ($row->schema_name ?? null) : null;
        $expectedSchema = config('integration-operations.database.schema', 'public');

        if (! is_string($expectedSchema)
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $expectedSchema) !== 1
            || ! is_string($schema)
            || ! hash_equals($expectedSchema, $schema)) {
            throw new RuntimeException('The Fakturownia sync checkpoint migration requires the configured PostgreSQL schema.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A Fakturownia sync checkpoint PostgreSQL identifier is invalid.');
        }

        return '"'.$schema.'"."'.$identifier.'"';
    }
};
