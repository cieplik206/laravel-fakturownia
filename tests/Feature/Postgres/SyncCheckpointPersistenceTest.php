<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Sync\DatabaseSyncCheckpointStore;
use Cieplik206\Fakturownia\Stateful\Sync\Exceptions\SyncCheckpointLeaseLost;
use Cieplik206\Fakturownia\Stateful\Sync\Exceptions\SyncCheckpointStorageUnavailable;
use Cieplik206\Fakturownia\Stateful\Sync\RemoteSyncCursor;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s810PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The sync checkpoint PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1'
        || preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The sync checkpoint PostgreSQL gate requires an opted-in test-only database.');
    }

    $port = getenv('FAKTUROWNIA_TEST_DB_PORT');
    $sslMode = getenv('FAKTUROWNIA_TEST_DB_SSLMODE');

    return [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => is_string($port) && ctype_digit($port) ? (int) $port : 5432,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => $schema,
        'sslmode' => is_string($sslMode) && $sslMode !== '' ? $sslMode : 'prefer',
    ];
}

function s810PostgresSchemaName(): string
{
    return 'fakturownia_sync_'.getmypid().'_'.bin2hex(random_bytes(6));
}

function s810QuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('The generated PostgreSQL schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s810AssertPostgresDatabase(Connection $connection, string $expectedDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if ($connection->getDriverName() !== 'pgsql'
        || ! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The sync checkpoint gate is connected to an unexpected database.');
    }
}

it('persists a fenced checkpoint atomically with its local work unit in real PostgreSQL', function (): void {
    $databaseManager = app(DatabaseManager::class);
    $schema = s810PostgresSchemaName();
    $quotedSchema = s810QuotedIdentifier($schema);
    $adminConfiguration = s810PostgresConfiguration();
    $testConfiguration = s810PostgresConfiguration($schema);

    config()->set('database.connections.fakturownia_sync_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_sync_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_sync_test');
    config()->set('integration-operations.database.schema', $schema);
    $databaseManager->purge('fakturownia_sync_admin');
    $databaseManager->purge('fakturownia_sync_test');
    $admin = $databaseManager->connection('fakturownia_sync_admin');
    s810AssertPostgresDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");

    try {
        $connection = $databaseManager->connection('fakturownia_sync_test');
        s810AssertPostgresDatabase($connection, $testConfiguration['database']);

        $exitCode = app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_sync_test',
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);

        $connection->statement(
            "CREATE TABLE {$quotedSchema}.\"fakturownia_sync_probe\" (remote_id varchar(191) PRIMARY KEY)",
        );

        $store = new DatabaseSyncCheckpointStore(
            $connection,
            $testConfiguration['database'],
            $schema,
        );
        $scope = new SyncIntegrityScope('ippro.primary', 'payments');
        $initial = $store->checkpoint($scope);

        expect($initial->cursor)->toBeNull()
            ->and($initial->leaseGeneration)->toBe(0);

        $lease = $store->acquire($scope, 60);

        expect($lease)->not->toBeNull()
            ->and($store->acquire($scope, 60))->toBeNull();

        if ($lease === null) {
            throw new RuntimeException('The initial sync checkpoint lease was not acquired.');
        }

        $firstCursor = new RemoteSyncCursor(
            new DateTimeImmutable('2026-08-27T10:00:00.000000+00:00'),
            '100',
        );

        expect(fn () => $store->advance($lease, $firstCursor))
            ->toThrow(LogicException::class, 'inside the transaction');

        $connection->transaction(function () use ($connection, $store, $lease, $firstCursor): void {
            $connection->table('fakturownia_sync_probe')->insert(['remote_id' => '100']);
            $store->advance($lease, $firstCursor);
        });

        $advanced = $store->checkpoint($scope);

        expect($advanced->cursor?->timestamp())->toBe($firstCursor->timestamp())
            ->and($advanced->cursor?->remoteId())->toBe('100')
            ->and($connection->table('fakturownia_sync_probe')->pluck('remote_id')->all())->toBe(['100']);

        $store->release($lease);
        $rollbackLease = $store->acquire($scope, 60);

        if ($rollbackLease === null) {
            throw new RuntimeException('The rollback sync checkpoint lease was not acquired.');
        }

        $secondCursor = new RemoteSyncCursor(
            new DateTimeImmutable('2026-08-27T10:01:00.000000+00:00'),
            '101',
        );

        try {
            $connection->transaction(function () use ($connection, $store, $rollbackLease, $secondCursor): void {
                $connection->table('fakturownia_sync_probe')->insert(['remote_id' => '101']);
                $store->advance($rollbackLease, $secondCursor);

                throw new RuntimeException('rollback complete work unit');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('rollback complete work unit');
        }

        $afterRollback = $store->checkpoint($scope);

        expect($afterRollback->cursor?->timestamp())->toBe($firstCursor->timestamp())
            ->and($afterRollback->cursor?->remoteId())->toBe('100')
            ->and($connection->table('fakturownia_sync_probe')->pluck('remote_id')->all())->toBe(['100']);

        $connection->transaction(function () use ($store, $rollbackLease, $firstCursor): void {
            $unchanged = $store->advance($rollbackLease, $firstCursor);

            expect($unchanged->cursor?->timestamp())->toBe($firstCursor->timestamp())
                ->and($unchanged->cursor?->remoteId())->toBe('100');
        });

        $store->release($rollbackLease);
        $currentLease = $store->acquire($scope, 60);

        expect($currentLease)->not->toBeNull()
            ->and(fn () => $store->release($rollbackLease))->toThrow(SyncCheckpointLeaseLost::class)
            ->and(fn () => new DatabaseSyncCheckpointStore(
                $connection,
                'definitely_not_the_connected_database',
                $schema,
            ))->toThrow(SyncCheckpointStorageUnavailable::class);

        if ($currentLease !== null) {
            $store->release($currentLease);
        }

        expect(fn (): int => $connection->table('fakturownia_sync_checkpoints')
            ->where('connection_key', $scope->connectionKey)
            ->where('lane', $scope->lane)
            ->delete())->toThrow(QueryException::class)
            ->and(fn (): bool => $connection->statement(
                "TRUNCATE TABLE {$quotedSchema}.\"fakturownia_sync_checkpoints\"",
            ))->toThrow(QueryException::class);
    } finally {
        $databaseManager->purge('fakturownia_sync_test');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $databaseManager->purge('fakturownia_sync_admin');
    }
})->group('postgres');
