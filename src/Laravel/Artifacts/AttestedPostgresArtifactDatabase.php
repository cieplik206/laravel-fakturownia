<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceRepository;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class AttestedPostgresArtifactDatabase
{
    private function __construct(
        private Connection $connection,
        private SharedDatabaseArtifactLockConfiguration $configuration,
    ) {}

    public static function attest(
        Connection $connection,
        SharedDatabaseArtifactLockConfiguration $configuration,
    ): self {
        self::assertConfigurationMatches($connection, $configuration);
        self::assertWriterSchema($connection, $configuration);

        return new self($connection, $configuration);
    }

    public function repository(): ArtifactMaintenanceRepository
    {
        return new DatabaseArtifactMaintenanceRepository(
            $this->connection,
            $this->configuration->qualifiedArtifactTable(),
        );
    }

    public function addressLock(int $leaseSeconds = 30, int $waitSeconds = 5): ArtifactAddressLock
    {
        return new CacheArtifactAddressLock(
            $this->connection,
            $this->configuration->qualifiedLockTable(),
            $leaseSeconds,
            $waitSeconds,
        );
    }

    private static function assertConfigurationMatches(
        Connection $connection,
        SharedDatabaseArtifactLockConfiguration $configuration,
    ): void {
        $connectionName = $connection->getName();
        $host = $connection->getConfig('host');
        $configuredPort = $connection->getConfig('port');
        $port = is_int($configuredPort)
            ? $configuredPort
            : (is_string($configuredPort) && ctype_digit($configuredPort) ? (int) $configuredPort : null);

        if ($connection->getDriverName() !== 'pgsql'
            || ! is_string($connectionName)
            || ! hash_equals($configuration->connectionName, $connectionName)
            || ! hash_equals($configuration->databaseName, $connection->getDatabaseName())
            || ! is_string($host)
            || ! hash_equals($configuration->host, $host)
            || $port !== $configuration->port) {
            throw new InvalidArgumentException('The artifact database does not match the verified shared PostgreSQL configuration.');
        }
    }

    private static function assertWriterSchema(
        Connection $connection,
        SharedDatabaseArtifactLockConfiguration $configuration,
    ): void {
        $result = $connection->selectOne(
            <<<'SQL'
                SELECT
                    current_database() AS database_name,
                    current_schema() AS schema_name,
                    to_regclass(?) IS NOT NULL AS artifact_table_exists,
                    to_regclass(?) IS NOT NULL AS lock_table_exists,
                    (
                        SELECT COUNT(*) = 3
                            AND COUNT(*) FILTER (
                                WHERE column_name = 'key'
                                    AND data_type = 'character varying'
                                    AND character_maximum_length = 255
                                    AND is_nullable = 'NO'
                            ) = 1
                            AND COUNT(*) FILTER (
                                WHERE column_name = 'owner'
                                    AND data_type = 'character varying'
                                    AND character_maximum_length = 255
                                    AND is_nullable = 'NO'
                            ) = 1
                            AND COUNT(*) FILTER (
                                WHERE column_name = 'expiration'
                                    AND data_type = 'integer'
                                    AND is_nullable = 'NO'
                            ) = 1
                        FROM information_schema.columns
                        WHERE table_schema = ? AND table_name = ?
                    ) AS lock_columns_valid,
                    EXISTS (
                        SELECT 1
                        FROM information_schema.table_constraints AS constraints
                        INNER JOIN information_schema.key_column_usage AS key_columns
                            ON key_columns.constraint_catalog = constraints.constraint_catalog
                            AND key_columns.constraint_schema = constraints.constraint_schema
                            AND key_columns.constraint_name = constraints.constraint_name
                        WHERE constraints.table_schema = ?
                            AND constraints.table_name = ?
                            AND constraints.constraint_type = 'PRIMARY KEY'
                        GROUP BY constraints.constraint_catalog, constraints.constraint_schema, constraints.constraint_name
                        HAVING array_agg(key_columns.column_name::text ORDER BY key_columns.ordinal_position) = ARRAY['key']::text[]
                    ) AS lock_primary_key_valid
                SQL,
            [
                $configuration->qualifiedArtifactTable(),
                $configuration->qualifiedLockTable(),
                $configuration->lockSchema,
                $configuration->lockTable,
                $configuration->lockSchema,
                $configuration->lockTable,
            ],
            false,
        );

        if (! $result instanceof stdClass
            || ! is_string($result->database_name ?? null)
            || ! hash_equals($configuration->databaseName, $result->database_name)
            || ! is_string($result->schema_name ?? null)
            || ! hash_equals($configuration->schema, $result->schema_name)
            || ($result->artifact_table_exists ?? null) !== true
            || ($result->lock_table_exists ?? null) !== true
            || ($result->lock_columns_valid ?? null) !== true
            || ($result->lock_primary_key_valid ?? null) !== true) {
            throw new RuntimeException('The writer connection does not expose the attested artifact and lock tables in the exact PostgreSQL schema.');
        }
    }
}
