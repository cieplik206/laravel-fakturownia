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
        Schema::create('fakturownia_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('connection_key', 128);
            $table->string('resource_type', 32);
            $table->string('local_type', 128);
            $table->unsignedSmallInteger('local_hmac_key_version');
            $table->char('local_reference_hmac', 64);
            $table->string('remote_id', 191);
            $table->string('remote_number', 191);
            $table->char('created_by_operation_id', 26);
            $table->char('last_operation_id', 26);
            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->unsignedSmallInteger('snapshot_key_version');
            $table->string('snapshot_cipher', 32);
            $table->string('snapshot_nonce', 32);
            $table->text('snapshot_ciphertext');
            $table->char('snapshot_ciphertext_sha256', 64);
            $table->unsignedSmallInteger('hmac_key_version');
            $table->char('snapshot_fingerprint_hmac', 64);
            $table->unsignedInteger('row_version');
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('remote_updated_at', precision: 6)->nullable();
            $table->timestampTz('last_seen_at', precision: 6);
            $table->timestampTz('synced_at', precision: 6);
            $table->timestampTz('deleted_remotely_at', precision: 6)->nullable();

            $table->unique(
                ['connection_key', 'resource_type', 'remote_id'],
                'fakturownia_resources_connection_remote_unique',
            );
            $table->unique(
                [
                    'connection_key',
                    'resource_type',
                    'local_type',
                    'local_hmac_key_version',
                    'local_reference_hmac',
                ],
                'fakturownia_resources_connection_local_unique',
            );
            $table->index(
                ['connection_key', 'resource_type', 'last_seen_at', 'id'],
                'fakturownia_resources_scoped_seen_idx',
            );
            $table->index('created_by_operation_id', 'fakturownia_resources_created_operation_idx');
            $table->index('last_operation_id', 'fakturownia_resources_last_operation_idx');
        });

        Schema::create('fakturownia_resource_local_lookups', function (Blueprint $table): void {
            $table->char('resource_id', 26);
            $table->string('connection_key', 128);
            $table->string('resource_type', 32);
            $table->string('local_type', 128);
            $table->unsignedSmallInteger('hmac_key_version');
            $table->char('local_reference_hmac', 64);
            $table->timestampTz('created_at', precision: 6);

            $table->foreign('resource_id', 'fakturownia_resource_local_lookups_resource_fk')
                ->references('id')
                ->on('fakturownia_resources');
            $table->unique(
                [
                    'connection_key',
                    'resource_type',
                    'local_type',
                    'hmac_key_version',
                    'local_reference_hmac',
                ],
                'fakturownia_resource_local_lookups_identity_unique',
            );
            $table->unique(
                ['resource_id', 'hmac_key_version'],
                'fakturownia_resource_local_lookups_resource_version_unique',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $schema = $this->postgresSchema($connection);

            foreach (['fakturownia_resource_local_lookups', 'fakturownia_resources'] as $table) {
                $connection->statement('DROP TABLE IF EXISTS '.$this->qualifiedIdentifier($schema, $table));
            }

            foreach ([
                'fakturownia_guard_resource_local_lookup_immutable',
                'fakturownia_guard_resource_local_lookup_truncate',
                'fakturownia_guard_resource_projection',
                'fakturownia_guard_resource_truncate',
            ] as $function) {
                $connection->statement('DROP FUNCTION IF EXISTS '.$this->qualifiedIdentifier($schema, $function).'()');
            }

            return;
        }

        Schema::dropIfExists('fakturownia_resource_local_lookups');
        Schema::dropIfExists('fakturownia_resources');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia resource database connection must be a string or null.');
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
            '{{resources}}' => $this->qualifiedIdentifier($schema, 'fakturownia_resources'),
            '{{lookups}}' => $this->qualifiedIdentifier($schema, 'fakturownia_resource_local_lookups'),
            '{{resource_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_resource_projection'),
            '{{resource_truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_resource_truncate'),
            '{{lookup_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_resource_local_lookup_immutable'),
            '{{lookup_truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_resource_local_lookup_truncate'),
        ];

        foreach ([
            <<<'SQL'
                ALTER TABLE {{resources}}
                ADD CONSTRAINT fakturownia_resources_identity_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND resource_type = 'invoice'
                    AND local_type = 'transaction_order'
                    AND local_hmac_key_version > 0
                    AND local_reference_hmac ~ '^[a-f0-9]{64}$'
                    AND octet_length(remote_id) BETWEEN 1 AND 191
                    AND octet_length(remote_number) BETWEEN 0 AND 191
                    AND remote_id !~ '[[:cntrl:]]'
                    AND remote_number !~ '[[:cntrl:]]'
                    AND created_by_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND last_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                ),
                ADD CONSTRAINT fakturownia_resources_snapshot_check CHECK (
                    snapshot_schema_version = 1
                    AND snapshot_key_version > 0
                    AND snapshot_cipher = 'XCHACHA20-POLY1305'
                    AND snapshot_nonce ~ '^[A-Za-z0-9+/]{32}$'
                    AND octet_length(snapshot_ciphertext) BETWEEN 24 AND 524288
                    AND snapshot_ciphertext ~ '^[A-Za-z0-9+/]+={0,2}$'
                    AND snapshot_ciphertext_sha256 ~ '^[a-f0-9]{64}$'
                    AND snapshot_ciphertext_sha256 = encode(
                        sha256(convert_to(snapshot_ciphertext, 'UTF8')),
                        'hex'
                    )
                    AND hmac_key_version > 0
                    AND snapshot_fingerprint_hmac ~ '^[a-f0-9]{64}$'
                ),
                ADD CONSTRAINT fakturownia_resources_lifecycle_check CHECK (
                    row_version > 0
                    AND created_at <= last_seen_at
                    AND last_seen_at <= synced_at
                    AND (deleted_remotely_at IS NULL OR deleted_remotely_at >= last_seen_at)
                )
                SQL,
            <<<'SQL'
                ALTER TABLE {{lookups}}
                ADD CONSTRAINT fakturownia_resource_local_lookups_check CHECK (
                    resource_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND resource_type = 'invoice'
                    AND local_type = 'transaction_order'
                    AND hmac_key_version > 0
                    AND local_reference_hmac ~ '^[a-f0-9]{64}$'
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{resource_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia resource mappings cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.resource_type IS DISTINCT FROM OLD.resource_type
                        OR NEW.local_type IS DISTINCT FROM OLD.local_type
                        OR NEW.remote_id IS DISTINCT FROM OLD.remote_id
                        OR NEW.created_by_operation_id IS DISTINCT FROM OLD.created_by_operation_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Fakturownia resource mapping identity is immutable' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.row_version <> OLD.row_version + 1
                        OR NEW.last_seen_at < OLD.last_seen_at
                        OR NEW.synced_at < OLD.synced_at
                        OR (OLD.deleted_remotely_at IS NOT NULL AND NEW.deleted_remotely_at IS DISTINCT FROM OLD.deleted_remotely_at) THEN
                        RAISE EXCEPTION 'Fakturownia resource mapping version or lifecycle is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_resources_projection_guard
                BEFORE UPDATE OR DELETE ON {{resources}}
                FOR EACH ROW EXECUTE FUNCTION {{resource_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{resource_truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia resource mappings cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_resources_no_truncate
                BEFORE TRUNCATE ON {{resources}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{resource_truncate_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{lookup_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia resource local lookups cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    RAISE EXCEPTION 'Fakturownia resource local lookups are immutable' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_resource_local_lookups_immutable
                BEFORE UPDATE OR DELETE ON {{lookups}}
                FOR EACH ROW EXECUTE FUNCTION {{lookup_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{lookup_truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia resource local lookups cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_resource_local_lookups_no_truncate
                BEFORE TRUNCATE ON {{lookups}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{lookup_truncate_function}}()
                SQL,
        ] as $statement) {
            $connection->statement(strtr($statement, $replacements));
        }
    }

    private function postgresSchema(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT current_schema() AS schema_name');
        $schema = $row instanceof stdClass ? ($row->schema_name ?? null) : null;

        if (! is_string($schema) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new RuntimeException('The Fakturownia resource migration requires one canonical PostgreSQL schema.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A Fakturownia resource PostgreSQL identifier is invalid.');
        }

        return '"'.$schema.'"."'.$identifier.'"';
    }
};
