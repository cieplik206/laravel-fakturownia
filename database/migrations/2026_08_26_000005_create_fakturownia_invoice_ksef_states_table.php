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
        Schema::create('fakturownia_invoice_ksef_states', function (Blueprint $table): void {
            $table->char('resource_id', 26)->primary();
            $table->string('connection_key', 128);
            $table->string('remote_id', 191);
            $table->char('last_operation_id', 26);
            $table->string('raw_status', 128);
            $table->string('status_category', 32);
            $table->string('government_id', 256)->nullable();
            $table->unsignedSmallInteger('provider_error_count');
            $table->boolean('offline');
            $table->boolean('configuration_blocked');
            $table->boolean('overdue');
            $table->char('observation_fingerprint', 64);
            $table->unsignedInteger('row_version');
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('observed_at', precision: 6);
            $table->timestampTz('accepted_at', precision: 6)->nullable();
            $table->timestampTz('rejected_at', precision: 6)->nullable();
            $table->timestampTz('overdue_at', precision: 6)->nullable();
            $table->timestampTz('updated_at', precision: 6);

            $table->foreign('resource_id', 'fakturownia_ksef_states_resource_fk')
                ->references('id')
                ->on('fakturownia_resources');
            $table->unique(
                ['connection_key', 'remote_id'],
                'fakturownia_ksef_states_connection_remote_unique',
            );
            $table->index('last_operation_id', 'fakturownia_ksef_states_operation_idx');
            $table->index(
                ['connection_key', 'status_category', 'observed_at'],
                'fakturownia_ksef_states_status_observed_idx',
            );
        });

        Schema::create('fakturownia_invoice_ksef_state_history', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->char('operation_id', 26);
            $table->char('resource_id', 26);
            $table->string('connection_key', 128);
            $table->string('remote_id', 191);
            $table->string('raw_status', 128);
            $table->string('status_category', 32);
            $table->string('government_id', 256)->nullable();
            $table->unsignedSmallInteger('provider_error_count');
            $table->boolean('offline');
            $table->boolean('configuration_blocked');
            $table->boolean('overdue');
            $table->char('observation_fingerprint', 64);
            $table->timestampTz('observed_at', precision: 6);

            $table->foreign('resource_id', 'fakturownia_ksef_history_resource_fk')
                ->references('id')
                ->on('fakturownia_resources');
            $table->unique(
                ['operation_id', 'observation_fingerprint'],
                'fakturownia_ksef_history_operation_observation_unique',
            );
            $table->index(
                ['resource_id', 'observed_at', 'id'],
                'fakturownia_ksef_history_resource_observed_idx',
            );
        });

        $this->addPostgresGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $schema = $this->postgresSchema($connection);

            foreach (['fakturownia_invoice_ksef_state_history', 'fakturownia_invoice_ksef_states'] as $table) {
                $connection->statement('DROP TABLE IF EXISTS '.$this->qualifiedIdentifier($schema, $table));
            }

            foreach ([
                'fakturownia_guard_ksef_state',
                'fakturownia_guard_ksef_state_truncate',
                'fakturownia_guard_ksef_history',
                'fakturownia_guard_ksef_history_truncate',
            ] as $function) {
                $connection->statement('DROP FUNCTION IF EXISTS '.$this->qualifiedIdentifier($schema, $function).'()');
            }

            return;
        }

        Schema::dropIfExists('fakturownia_invoice_ksef_state_history');
        Schema::dropIfExists('fakturownia_invoice_ksef_states');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia KSeF database connection must be a string or null.');
        }

        return $connection;
    }

    private function addPostgresGuards(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = $this->postgresSchema($connection);
        $replacements = [
            '{{states}}' => $this->qualifiedIdentifier($schema, 'fakturownia_invoice_ksef_states'),
            '{{history}}' => $this->qualifiedIdentifier($schema, 'fakturownia_invoice_ksef_state_history'),
            '{{state_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_ksef_state'),
            '{{state_truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_ksef_state_truncate'),
            '{{history_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_ksef_history'),
            '{{history_truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_ksef_history_truncate'),
        ];

        foreach ([
            <<<'SQL'
                ALTER TABLE {{states}}
                ADD CONSTRAINT fakturownia_ksef_states_identity_check CHECK (
                    resource_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND octet_length(remote_id) BETWEEN 1 AND 191
                    AND remote_id !~ '[[:cntrl:]]'
                    AND last_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND octet_length(raw_status) BETWEEN 1 AND 128
                    AND raw_status ~ '^[a-z0-9][a-z0-9._:-]*$'
                    AND status_category IN (
                        'not_sent', 'succeeded', 'processing', 'status_check_error',
                        'technical_error', 'offline', 'duplicate', 'configuration_blocked',
                        'not_applicable', 'rejected', 'unknown'
                    )
                    AND (government_id IS NULL OR (
                        octet_length(government_id) BETWEEN 1 AND 256
                        AND government_id !~ '[[:cntrl:]]'
                    ))
                    AND provider_error_count BETWEEN 0 AND 10000
                    AND observation_fingerprint ~ '^[a-f0-9]{64}$'
                ),
                ADD CONSTRAINT fakturownia_ksef_states_semantics_check CHECK (
                    (status_category <> 'succeeded' OR government_id IS NOT NULL)
                    AND offline = (status_category = 'offline')
                    AND configuration_blocked = (status_category = 'configuration_blocked')
                    AND overdue = (overdue_at IS NOT NULL)
                    AND (accepted_at IS NULL OR status_category = 'succeeded')
                    AND (rejected_at IS NULL OR status_category IN ('not_applicable', 'rejected'))
                ),
                ADD CONSTRAINT fakturownia_ksef_states_lifecycle_check CHECK (
                    row_version > 0
                    AND created_at <= observed_at
                    AND observed_at <= updated_at
                    AND (accepted_at IS NULL OR accepted_at >= created_at)
                    AND (rejected_at IS NULL OR rejected_at >= created_at)
                    AND (overdue_at IS NULL OR overdue_at >= created_at)
                )
                SQL,
            <<<'SQL'
                ALTER TABLE {{history}}
                ADD CONSTRAINT fakturownia_ksef_history_check CHECK (
                    id ~ '^[a-f0-9]{64}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND resource_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND octet_length(remote_id) BETWEEN 1 AND 191
                    AND octet_length(raw_status) BETWEEN 1 AND 128
                    AND status_category IN (
                        'not_sent', 'succeeded', 'processing', 'status_check_error',
                        'technical_error', 'offline', 'duplicate', 'configuration_blocked',
                        'not_applicable', 'rejected', 'unknown'
                    )
                    AND (government_id IS NULL OR octet_length(government_id) BETWEEN 1 AND 256)
                    AND provider_error_count BETWEEN 0 AND 10000
                    AND observation_fingerprint ~ '^[a-f0-9]{64}$'
                    AND offline = (status_category = 'offline')
                    AND configuration_blocked = (status_category = 'configuration_blocked')
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{state_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia KSeF state cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.resource_id IS DISTINCT FROM OLD.resource_id
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.remote_id IS DISTINCT FROM OLD.remote_id
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.row_version <> OLD.row_version + 1 THEN
                        RAISE EXCEPTION 'Fakturownia KSeF state identity or version is invalid' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.status_category = 'succeeded'
                        AND (NEW.status_category <> 'succeeded' OR NEW.government_id IS DISTINCT FROM OLD.government_id) THEN
                        RAISE EXCEPTION 'Fakturownia accepted KSeF state cannot regress' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_ksef_states_guard
                BEFORE UPDATE OR DELETE ON {{states}}
                FOR EACH ROW EXECUTE FUNCTION {{state_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{state_truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia KSeF state cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_ksef_states_no_truncate
                BEFORE TRUNCATE ON {{states}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{state_truncate_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{history_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia KSeF history is immutable' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_ksef_history_immutable
                BEFORE UPDATE OR DELETE ON {{history}}
                FOR EACH ROW EXECUTE FUNCTION {{history_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{history_truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia KSeF history cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_ksef_history_no_truncate
                BEFORE TRUNCATE ON {{history}}
                FOR EACH STATEMENT EXECUTE FUNCTION {{history_truncate_function}}()
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
            throw new RuntimeException('The Fakturownia KSeF migration requires one canonical PostgreSQL schema.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A Fakturownia KSeF PostgreSQL identifier is invalid.');
        }

        return '"'.$schema.'"."'.$identifier.'"';
    }
};
