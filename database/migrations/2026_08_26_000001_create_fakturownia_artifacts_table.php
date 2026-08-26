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
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $this->createPostgresLockTable($connection);
        } else {
            Schema::create('fakturownia_artifact_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }

        Schema::create('fakturownia_artifacts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('connection_key', 128);
            $table->char('operation_id', 26);
            $table->char('resource_id', 26);
            $table->string('artifact_type', 64);
            $table->char('revision_key_hmac', 64);
            $table->char('source_snapshot_fingerprint_hmac', 64);
            $table->char('source_ksef_operation_id', 26)->nullable();
            $table->unsignedSmallInteger('source_gov_id_key_version')->nullable();
            $table->string('source_gov_id_cipher', 32)->nullable();
            $table->text('source_gov_id_ciphertext')->nullable();
            $table->char('source_gov_id_ciphertext_sha256', 64)->nullable();
            $table->string('disk', 128);
            $table->string('storage_prefix', 191);
            $table->string('content_address', 80);
            $table->unsignedSmallInteger('storage_key_version');
            $table->string('storage_key_cipher', 32);
            $table->text('storage_key_ciphertext');
            $table->char('storage_key_ciphertext_sha256', 64);
            $table->char('content_sha256', 64);
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 32);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('ready_at', precision: 6);
            $table->timestampTz('expires_at', precision: 6)->nullable();
            $table->timestampTz('deleted_at', precision: 6)->nullable();

            $table->unique(
                ['connection_key', 'resource_id', 'artifact_type', 'revision_key_hmac'],
                'fakturownia_artifacts_resource_revision_unique',
            );
            $table->index(
                ['connection_key', 'operation_id'],
                'fakturownia_artifacts_connection_operation_idx',
            );
            $table->index(
                ['connection_key', 'source_ksef_operation_id'],
                'fakturownia_artifacts_connection_ksef_operation_idx',
            );
            $table->index(
                ['disk', 'storage_prefix', 'content_address'],
                'fakturownia_artifacts_object_idx',
            );
            $table->index(
                ['status', 'expires_at'],
                'fakturownia_artifacts_retention_idx',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $schema = $this->postgresSchema($connection);
            $lockSchema = $this->postgresLockSchema();
            $connection->statement('DROP TABLE IF EXISTS '.$this->qualifiedIdentifier($schema, 'fakturownia_artifacts'));
            $connection->statement('DROP TABLE IF EXISTS '.$this->qualifiedIdentifier($lockSchema, 'fakturownia_artifact_locks'));
            $connection->statement('DROP FUNCTION IF EXISTS '.$this->qualifiedIdentifier($schema, 'fakturownia_guard_artifact_descriptor_immutable').'()');
            $connection->statement('DROP FUNCTION IF EXISTS '.$this->qualifiedIdentifier($schema, 'fakturownia_guard_artifact_descriptor_truncate').'()');

            return;
        }

        Schema::dropIfExists('fakturownia_artifacts');
        Schema::dropIfExists('fakturownia_artifact_locks');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia artifact database connection must be a string or null.');
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
            '{{table}}' => $this->qualifiedIdentifier($schema, 'fakturownia_artifacts'),
            '{{row_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_artifact_descriptor_immutable'),
            '{{truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_artifact_descriptor_truncate'),
        ];

        foreach ([
            <<<'SQL'
                ALTER TABLE {{table}}
                ADD CONSTRAINT fakturownia_artifacts_identity_shape_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND resource_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND (source_ksef_operation_id IS NULL OR source_ksef_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND artifact_type IN ('invoice_pdf', 'ksef_xml', 'ksef_upo', 'attachment')
                    AND revision_key_hmac ~ '^[a-f0-9]{64}$'
                    AND source_snapshot_fingerprint_hmac ~ '^[a-f0-9]{64}$'
                ),
                ADD CONSTRAINT fakturownia_artifacts_object_integrity_check CHECK (
                    content_sha256 ~ '^[a-f0-9]{64}$'
                    AND content_address = 'sha256:' || content_sha256
                    AND storage_key_version > 0
                    AND storage_key_cipher IN ('AES-128-GCM', 'AES-256-GCM')
                    AND octet_length(storage_key_ciphertext) BETWEEN 1 AND 16384
                    AND storage_key_ciphertext_sha256 = encode(
                        sha256(convert_to(storage_key_ciphertext, 'UTF8')),
                        'hex'
                    )
                    AND disk ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    AND storage_prefix ~ '^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$'
                    AND mime_type ~ '^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,62}$'
                    AND size_bytes > 0
                ),
                ADD CONSTRAINT fakturownia_artifacts_gov_id_envelope_check CHECK (
                    (source_gov_id_key_version IS NULL
                        AND source_gov_id_cipher IS NULL
                        AND source_gov_id_ciphertext IS NULL
                        AND source_gov_id_ciphertext_sha256 IS NULL)
                    OR (num_nonnulls(
                            source_gov_id_key_version,
                            source_gov_id_cipher,
                            source_gov_id_ciphertext,
                            source_gov_id_ciphertext_sha256
                        ) = 4
                        AND source_gov_id_key_version > 0
                        AND source_gov_id_cipher IN ('AES-128-GCM', 'AES-256-GCM')
                        AND octet_length(source_gov_id_ciphertext) BETWEEN 1 AND 16384
                        AND source_gov_id_ciphertext_sha256 = encode(
                            sha256(convert_to(source_gov_id_ciphertext, 'UTF8')),
                            'hex'
                        ))
                ),
                ADD CONSTRAINT fakturownia_artifacts_lifecycle_check CHECK (
                    status IN ('ready', 'quarantined', 'deleted')
                    AND created_at <= ready_at
                    AND (expires_at IS NULL OR expires_at > ready_at)
                    AND ((status = 'deleted') = (deleted_at IS NOT NULL))
                    AND (deleted_at IS NULL OR deleted_at >= ready_at)
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{row_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia artifact descriptors cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.status = 'deleted' THEN
                        RAISE EXCEPTION 'Fakturownia artifact tombstones are terminal' USING ERRCODE = '55000';
                    END IF;

                    IF NOT (
                        (OLD.status = 'ready' AND NEW.status IN ('ready', 'quarantined'))
                        OR (OLD.status = 'quarantined' AND NEW.status IN ('quarantined', 'deleted'))
                    ) THEN
                        RAISE EXCEPTION 'Fakturownia artifact lifecycle transition is invalid' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.resource_id IS DISTINCT FROM OLD.resource_id
                        OR NEW.artifact_type IS DISTINCT FROM OLD.artifact_type
                        OR NEW.revision_key_hmac IS DISTINCT FROM OLD.revision_key_hmac
                        OR NEW.source_snapshot_fingerprint_hmac IS DISTINCT FROM OLD.source_snapshot_fingerprint_hmac
                        OR NEW.source_ksef_operation_id IS DISTINCT FROM OLD.source_ksef_operation_id
                        OR NEW.source_gov_id_key_version IS DISTINCT FROM OLD.source_gov_id_key_version
                        OR NEW.source_gov_id_cipher IS DISTINCT FROM OLD.source_gov_id_cipher
                        OR NEW.source_gov_id_ciphertext IS DISTINCT FROM OLD.source_gov_id_ciphertext
                        OR NEW.source_gov_id_ciphertext_sha256 IS DISTINCT FROM OLD.source_gov_id_ciphertext_sha256
                        OR NEW.disk IS DISTINCT FROM OLD.disk
                        OR NEW.storage_prefix IS DISTINCT FROM OLD.storage_prefix
                        OR NEW.content_address IS DISTINCT FROM OLD.content_address
                        OR NEW.storage_key_version IS DISTINCT FROM OLD.storage_key_version
                        OR NEW.storage_key_cipher IS DISTINCT FROM OLD.storage_key_cipher
                        OR NEW.storage_key_ciphertext IS DISTINCT FROM OLD.storage_key_ciphertext
                        OR NEW.storage_key_ciphertext_sha256 IS DISTINCT FROM OLD.storage_key_ciphertext_sha256
                        OR NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
                        OR NEW.mime_type IS DISTINCT FROM OLD.mime_type
                        OR NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.ready_at IS DISTINCT FROM OLD.ready_at
                        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
                        RAISE EXCEPTION 'Fakturownia artifact descriptor metadata is immutable' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_artifacts_descriptor_immutable
                BEFORE UPDATE OR DELETE ON {{table}}
                FOR EACH ROW EXECUTE FUNCTION {{row_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia artifact descriptors cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_artifacts_no_truncate
                BEFORE TRUNCATE ON {{table}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{truncate_function}}()
                SQL,
        ] as $statement) {
            $connection->statement(strtr($statement, $replacements));
        }
    }

    private function createPostgresLockTable(Connection $connection): void
    {
        $lockSchema = $this->postgresLockSchema();
        $table = $this->qualifiedIdentifier($lockSchema, 'fakturownia_artifact_locks');
        $index = '"fakturownia_artifact_locks_expiration_index"';

        $connection->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                key VARCHAR(255) PRIMARY KEY,
                owner VARCHAR(255) NOT NULL,
                expiration INTEGER NOT NULL
            )
            SQL);
        $connection->statement("CREATE INDEX IF NOT EXISTS {$index} ON {$table} (expiration)");
    }

    private function postgresSchema(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT current_schema() AS schema_name');
        $schema = $row instanceof stdClass ? ($row->schema_name ?? null) : null;

        if (! is_string($schema) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new RuntimeException('The Fakturownia artifact migration requires one canonical PostgreSQL descriptor schema.');
        }

        return $schema;
    }

    private function postgresLockSchema(): string
    {
        $schema = config('fakturownia.artifacts.lock_schema', 'public');

        if (! is_string($schema) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new RuntimeException('The Fakturownia artifact migration requires one canonical PostgreSQL coordination schema.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A Fakturownia artifact PostgreSQL identifier is invalid.');
        }

        return '"'.$schema.'"."'.$identifier.'"';
    }
};
