<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceConstraints($connection, "local_type IN ('transaction_order', 'customer_return')");
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceConstraints($connection, "local_type = 'transaction_order'");
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

    private function replaceConstraints(Connection $connection, string $localTypePredicate): void
    {
        $schema = $this->postgresSchema($connection);
        $resources = $this->qualifiedIdentifier($schema, 'fakturownia_resources');
        $lookups = $this->qualifiedIdentifier($schema, 'fakturownia_resource_local_lookups');

        $connection->statement(<<<SQL
            ALTER TABLE {$resources}
            DROP CONSTRAINT fakturownia_resources_identity_check,
            ADD CONSTRAINT fakturownia_resources_identity_check CHECK (
                id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                AND resource_type = 'invoice'
                AND {$localTypePredicate}
                AND local_hmac_key_version > 0
                AND local_reference_hmac ~ '^[a-f0-9]{64}$'
                AND octet_length(remote_id) BETWEEN 1 AND 191
                AND octet_length(remote_number) BETWEEN 0 AND 191
                AND remote_id !~ '[[:cntrl:]]'
                AND remote_number !~ '[[:cntrl:]]'
                AND created_by_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                AND last_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
            )
        SQL);

        $connection->statement(<<<SQL
            ALTER TABLE {$lookups}
            DROP CONSTRAINT fakturownia_resource_local_lookups_check,
            ADD CONSTRAINT fakturownia_resource_local_lookups_check CHECK (
                resource_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                AND connection_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
                AND resource_type = 'invoice'
                AND {$localTypePredicate}
                AND hmac_key_version > 0
                AND local_reference_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
    }

    private function postgresSchema(Connection $connection): string
    {
        $schema = $connection->getConfig('search_path') ?? $connection->getConfig('schema') ?? 'public';

        if (is_array($schema)) {
            $schema = $schema[0] ?? null;
        }

        if (! is_string($schema)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The Fakturownia resource schema is invalid.');
        }

        return $schema;
    }

    private function qualifiedIdentifier(string $schema, string $table): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new InvalidArgumentException('The Fakturownia resource table is invalid.');
        }

        return '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $table).'"';
    }
};
