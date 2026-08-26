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
        Schema::create('fakturownia_webhook_receipts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('connection_key', 128);
            $table->char('provider_delivery_id_hmac', 64)->nullable();
            $table->char('payload_hmac', 64);
            $table->string('signature_status', 16);
            $table->unsignedSmallInteger('payload_key_version');
            $table->string('payload_cipher', 32);
            $table->string('payload_nonce', 32);
            $table->text('payload_ciphertext');
            $table->char('payload_ciphertext_sha256', 64);
            $table->unsignedInteger('delivery_count')->default(1);
            $table->timestampTz('received_at', precision: 6);
            $table->timestampTz('last_received_at', precision: 6);

            $table->unique(
                ['connection_key', 'provider_delivery_id_hmac'],
                'fakturownia_webhook_receipts_connection_delivery_unique',
            );
            $table->index(
                ['connection_key', 'payload_hmac', 'last_received_at'],
                'fakturownia_webhook_receipts_payload_window_idx',
            );
            $table->index(
                ['connection_key', 'received_at', 'id'],
                'fakturownia_webhook_receipts_intake_idx',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $schema = $this->postgresSchema($connection);
            $table = $this->qualifiedIdentifier($schema, 'fakturownia_webhook_receipts');
            $rowFunction = $this->qualifiedIdentifier($schema, 'fakturownia_guard_webhook_receipt_immutable');
            $truncateFunction = $this->qualifiedIdentifier($schema, 'fakturownia_guard_webhook_receipt_truncate');

            $connection->statement("DROP TABLE IF EXISTS {$table}");
            $connection->statement("DROP FUNCTION IF EXISTS {$rowFunction}()");
            $connection->statement("DROP FUNCTION IF EXISTS {$truncateFunction}()");

            return;
        }

        Schema::dropIfExists('fakturownia_webhook_receipts');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia webhook database connection must be a string or null.');
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
            '{{table}}' => $this->qualifiedIdentifier($schema, 'fakturownia_webhook_receipts'),
            '{{row_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_webhook_receipt_immutable'),
            '{{truncate_function}}' => $this->qualifiedIdentifier($schema, 'fakturownia_guard_webhook_receipt_truncate'),
        ];

        foreach ([
            <<<'SQL'
                ALTER TABLE {{table}}
                ADD CONSTRAINT fakturownia_webhook_receipts_identity_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                    AND (provider_delivery_id_hmac IS NULL OR provider_delivery_id_hmac ~ '^[a-f0-9]{64}$')
                    AND payload_hmac ~ '^[a-f0-9]{64}$'
                ),
                ADD CONSTRAINT fakturownia_webhook_receipts_payload_envelope_check CHECK (
                    signature_status IN ('unverified', 'verified')
                    AND payload_key_version > 0
                    AND payload_cipher = 'XCHACHA20-POLY1305'
                    AND payload_nonce ~ '^[A-Za-z0-9+/]{32}$'
                    AND octet_length(payload_ciphertext) BETWEEN 24 AND 1398124
                    AND payload_ciphertext ~ '^[A-Za-z0-9+/]+={0,2}$'
                    AND payload_ciphertext_sha256 ~ '^[a-f0-9]{64}$'
                    AND payload_ciphertext_sha256 = encode(
                        sha256(convert_to(payload_ciphertext, 'UTF8')),
                        'hex'
                    )
                ),
                ADD CONSTRAINT fakturownia_webhook_receipts_delivery_check CHECK (
                    delivery_count BETWEEN 1 AND 2147483647
                    AND received_at <= last_received_at
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{row_function}}() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Fakturownia webhook receipts cannot be physically deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.provider_delivery_id_hmac IS DISTINCT FROM OLD.provider_delivery_id_hmac
                        OR NEW.payload_hmac IS DISTINCT FROM OLD.payload_hmac
                        OR NEW.payload_key_version IS DISTINCT FROM OLD.payload_key_version
                        OR NEW.payload_cipher IS DISTINCT FROM OLD.payload_cipher
                        OR NEW.payload_nonce IS DISTINCT FROM OLD.payload_nonce
                        OR NEW.payload_ciphertext IS DISTINCT FROM OLD.payload_ciphertext
                        OR NEW.payload_ciphertext_sha256 IS DISTINCT FROM OLD.payload_ciphertext_sha256
                        OR NEW.received_at IS DISTINCT FROM OLD.received_at THEN
                        RAISE EXCEPTION 'Fakturownia webhook receipt identity and payload are immutable' USING ERRCODE = '55000';
                    END IF;

                    IF NOT (
                        NEW.signature_status = OLD.signature_status
                        OR (OLD.signature_status = 'unverified' AND NEW.signature_status = 'verified')
                    ) THEN
                        RAISE EXCEPTION 'Fakturownia webhook signature trust cannot be downgraded' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.delivery_count <> OLD.delivery_count + 1
                        OR NEW.last_received_at < OLD.last_received_at THEN
                        RAISE EXCEPTION 'Fakturownia webhook redelivery metadata is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_webhook_receipts_immutable
                BEFORE UPDATE OR DELETE ON {{table}}
                FOR EACH ROW EXECUTE FUNCTION {{row_function}}()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION {{truncate_function}}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Fakturownia webhook receipts cannot be truncated' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER fakturownia_webhook_receipts_no_truncate
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
            throw new RuntimeException('The Fakturownia webhook migration requires the configured PostgreSQL schema.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A Fakturownia webhook PostgreSQL identifier is invalid.');
        }

        return '"'.$schema.'"."'.$identifier.'"';
    }
};
