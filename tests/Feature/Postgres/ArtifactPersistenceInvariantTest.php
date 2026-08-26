<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\Ulid;

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s61PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The Fakturownia PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1') {
        throw new RuntimeException('The Fakturownia PostgreSQL schema gate requires explicit opt-in.');
    }

    if (preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The Fakturownia PostgreSQL gate requires a test-only database name.');
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

function s61PostgresSchemaName(): string
{
    return 'fakturownia_artifact_'.getmypid().'_'.bin2hex(random_bytes(6));
}

function s61PostgresQuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('The generated PostgreSQL schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s61AssertPostgresDatabase(Connection $connection, string $expectedDatabase): void
{
    if ($connection->getDriverName() !== 'pgsql') {
        throw new RuntimeException('The Fakturownia persistence gate must use PostgreSQL.');
    }

    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The connected PostgreSQL database does not match the guarded test database.');
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function s61PostgresArtifactRow(array $overrides = []): array
{
    $storageKeyCiphertext = 'encrypted-storage-key';

    return array_replace([
        'id' => (string) new Ulid,
        'connection_key' => 'tenant:postgres',
        'operation_id' => (string) new Ulid,
        'resource_id' => (string) new Ulid,
        'artifact_type' => 'invoice_pdf',
        'revision_key_hmac' => hash('sha256', (string) new Ulid),
        'source_snapshot_fingerprint_hmac' => hash('sha256', (string) new Ulid),
        'source_ksef_operation_id' => null,
        'source_gov_id_key_version' => null,
        'source_gov_id_cipher' => null,
        'source_gov_id_ciphertext' => null,
        'source_gov_id_ciphertext_sha256' => null,
        'disk' => 'shared-artifacts',
        'storage_prefix' => 'fakturownia/finalized',
        'content_address' => 'sha256:'.str_repeat('c', 64),
        'storage_key_version' => 1,
        'storage_key_cipher' => 'AES-256-GCM',
        'storage_key_ciphertext' => $storageKeyCiphertext,
        'storage_key_ciphertext_sha256' => hash('sha256', $storageKeyCiphertext),
        'content_sha256' => str_repeat('c', 64),
        'mime_type' => 'application/pdf',
        'size_bytes' => 1_024,
        'status' => 'ready',
        'created_at' => '2026-08-26 01:00:00.000000+00:00',
        'ready_at' => '2026-08-26 01:00:01.000000+00:00',
        'expires_at' => '2026-11-24 01:00:01.000000+00:00',
        'deleted_at' => null,
    ], $overrides);
}

it('enforces artifact integrity and terminal tombstones in real PostgreSQL', function (): void {
    $databaseManager = app(DatabaseManager::class);

    $schema = s61PostgresSchemaName();
    $quotedSchema = s61PostgresQuotedIdentifier($schema);
    $adminConfiguration = s61PostgresConfiguration();
    $testConfiguration = s61PostgresConfiguration($schema);

    config()->set('database.connections.fakturownia_artifact_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_artifact_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_artifact_test');
    config()->set('integration-operations.database.schema', $schema);
    config()->set('fakturownia.artifacts.lock_schema', $schema);
    $databaseManager->purge('fakturownia_artifact_admin');
    $databaseManager->purge('fakturownia_artifact_test');
    $admin = $databaseManager->connection('fakturownia_artifact_admin');
    s61AssertPostgresDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");

    try {
        $connection = $databaseManager->connection('fakturownia_artifact_test');
        s61AssertPostgresDatabase($connection, $testConfiguration['database']);

        $currentSchema = $connection->selectOne('SELECT current_schema() AS schema_name');
        expect($currentSchema)->toBeInstanceOf(stdClass::class)
            ->and($currentSchema->schema_name ?? null)->toBe($schema);

        $exitCode = app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_artifact_test',
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(Schema::connection('fakturownia_artifact_test')->getForeignKeys('fakturownia_artifacts'))->toBe([]);

        $table = $connection->table('fakturownia_artifacts');
        $ready = s61PostgresArtifactRow();
        expect($table->insert($ready))->toBeTrue();

        expect(fn (): bool => $table->insert(s61PostgresArtifactRow([
            'storage_key_cipher' => '',
            'storage_key_ciphertext' => '',
            'storage_key_ciphertext_sha256' => hash('sha256', ''),
        ])))->toThrow(QueryException::class);

        expect(fn (): bool => $table->insert(s61PostgresArtifactRow([
            'storage_key_cipher' => 'AES-256-CBC',
        ])))->toThrow(QueryException::class);

        expect(fn (): bool => $table->insert(s61PostgresArtifactRow([
            'storage_key_ciphertext_sha256' => str_repeat('f', 64),
        ])))->toThrow(QueryException::class);

        expect(fn (): bool => $table->insert(s61PostgresArtifactRow([
            'source_gov_id_key_version' => 1,
            'source_gov_id_cipher' => 'AES-256-GCM',
            'source_gov_id_ciphertext' => '',
            'source_gov_id_ciphertext_sha256' => hash('sha256', ''),
        ])))->toThrow(QueryException::class);

        expect(fn (): bool => $table->insert(s61PostgresArtifactRow([
            'content_address' => 'sha256:'.str_repeat('d', 64),
        ])))->toThrow(QueryException::class);

        $govIdCiphertext = 'encrypted-gov-id';
        expect($table->insert(s61PostgresArtifactRow([
            'source_gov_id_key_version' => 1,
            'source_gov_id_cipher' => 'AES-128-GCM',
            'source_gov_id_ciphertext' => $govIdCiphertext,
            'source_gov_id_ciphertext_sha256' => hash('sha256', $govIdCiphertext),
        ])))->toBeTrue();

        expect(fn (): int => $connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->update(['expires_at' => '2027-01-01 00:00:00.000000+00:00']))
            ->toThrow(QueryException::class);

        expect(fn (): int => $connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->update(['content_sha256' => str_repeat('e', 64)]))
            ->toThrow(QueryException::class);

        expect(fn (): int => $connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->update([
                'status' => 'deleted',
                'deleted_at' => '2026-08-27 01:00:00.000000+00:00',
            ]))->toThrow(QueryException::class);

        expect($connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->update(['status' => 'quarantined']))->toBe(1)
            ->and(fn (): int => $connection->table('fakturownia_artifacts')
                ->where('id', $ready['id'])
                ->update(['status' => 'ready']))->toThrow(QueryException::class)
            ->and($connection->table('fakturownia_artifacts')
                ->where('id', $ready['id'])
                ->update([
                    'status' => 'deleted',
                    'deleted_at' => '2026-08-27 01:00:00.000000+00:00',
                ]))->toBe(1);

        expect(fn (): int => $connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->update([
                'status' => 'ready',
                'deleted_at' => null,
            ]))->toThrow(QueryException::class);

        expect(fn (): int => $connection->table('fakturownia_artifacts')
            ->where('id', $ready['id'])
            ->delete())->toThrow(QueryException::class);

        $rowCount = $connection->table('fakturownia_artifacts')->count();
        expect(fn (): bool => $connection->statement("TRUNCATE TABLE {$quotedSchema}.\"fakturownia_artifacts\""))
            ->toThrow(QueryException::class)
            ->and($connection->table('fakturownia_artifacts')->count())->toBe($rowCount);
    } finally {
        $databaseManager->purge('fakturownia_artifact_test');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $databaseManager->purge('fakturownia_artifact_admin');
    }
})->group('postgres');
