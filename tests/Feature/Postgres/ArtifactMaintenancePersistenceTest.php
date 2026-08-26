<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\AttestedPostgresArtifactDatabase;
use Cieplik206\Fakturownia\Laravel\Artifacts\DatabaseArtifactMaintenanceRepository;
use Cieplik206\Fakturownia\Laravel\Artifacts\PostgresArtifactMaintenanceManagerFactory;
use Cieplik206\Fakturownia\Laravel\Artifacts\SharedDatabaseArtifactLockConfiguration;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceIssue;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectObservation;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeDeadline;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeOutcome;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermit;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitIssuer;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitVerifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStorageTopology;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStoreCapabilities;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions\ArtifactPurgeUnauthorized;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Symfony\Component\Uid\Ulid;

final class S64PostgresArtifactStream extends ArtifactContentStream
{
    private int $offset = 0;

    public function __construct(private readonly string $contents) {}

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->offset, $length);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function close(): void {}
}

final class S64PostgresArtifactStore implements ArtifactMaintenanceStore
{
    /** @var array<string, ArtifactObjectObservation> */
    public array $observations = [];

    /** @var array<string, string> */
    public array $contents = [];

    /** @var list<string> */
    public array $deleted = [];

    public function __construct(private readonly ArtifactPurgePermitVerifier $purgePermitVerifier) {}

    public function capabilities(ArtifactStorageNamespace $storageNamespace): ArtifactStoreCapabilities
    {
        return new ArtifactStoreCapabilities(true, true, true, true, true, 10);
    }

    public function scanFinalized(
        ArtifactStorageNamespace $storageNamespace,
        DateTimeImmutable $notModifiedAfter,
        ?ContentAddress $after,
        int $limit,
    ): ArtifactObjectPage {
        $objects = array_values(array_filter(
            $this->observations,
            static fn (ArtifactObjectObservation $observation): bool => $observation->lastModifiedAt <= $notModifiedAfter
                && ($after === null || (string) $observation->object->contentAddress > (string) $after),
        ));
        usort(
            $objects,
            static fn (ArtifactObjectObservation $left, ArtifactObjectObservation $right): int => (string) $left->object->contentAddress <=> (string) $right->object->contentAddress,
        );

        return new ArtifactObjectPage(array_slice($objects, 0, $limit), null);
    }

    public function inspectFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ?ArtifactObjectObservation {
        return $this->observations[(string) $contentAddress] ?? null;
    }

    public function openFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactContentStream {
        $contents = $this->contents[(string) $contentAddress] ?? null;

        if (! is_string($contents)) {
            throw new RuntimeException('The PostgreSQL gate object is absent.');
        }

        return new S64PostgresArtifactStream($contents);
    }

    public function purgeOrphan(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome {
        $this->purgePermitVerifier->consumeOrphan($permit, $storageNamespace, $observation, $deadline);

        return $this->purge($observation);
    }

    public function purgeExpired(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome {
        $this->purgePermitVerifier->consumeExpired($permit, $storageNamespace, $record, $observation, $deadline);

        return $this->purge($observation);
    }

    private function purge(ArtifactObjectObservation $observation): ArtifactPurgeOutcome
    {
        $address = (string) $observation->object->contentAddress;
        $current = $this->observations[$address] ?? null;

        if ($current === null) {
            return ArtifactPurgeOutcome::AlreadyAbsent;
        }

        if (! hash_equals($observation->generationFingerprintSha256, $current->generationFingerprintSha256)) {
            return ArtifactPurgeOutcome::RejectedChanged;
        }

        $this->deleted[] = $address;
        unset($this->observations[$address], $this->contents[$address]);

        return ArtifactPurgeOutcome::Deleted;
    }
}

final class S64PostgresArtifactStoreFactory implements ArtifactMaintenanceStoreFactory
{
    public ?S64PostgresArtifactStore $store = null;

    public int $makeCalls = 0;

    public function make(ArtifactPurgePermitVerifier $purgePermitVerifier): ArtifactMaintenanceStore
    {
        $this->makeCalls++;

        return $this->store = new S64PostgresArtifactStore($purgePermitVerifier);
    }
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s64PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The S6.4 PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1') {
        throw new RuntimeException('The S6.4 PostgreSQL schema gate requires explicit opt-in.');
    }

    if (preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The S6.4 PostgreSQL gate requires a test-only database name.');
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

function s64PostgresSchemaName(): string
{
    return 'fakturownia_maintenance_'.getmypid().'_'.bin2hex(random_bytes(5));
}

function s64PostgresQuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new RuntimeException('The S6.4 PostgreSQL schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s64AssertPostgresDatabase(Connection $connection, string $expectedDatabase): void
{
    if ($connection->getDriverName() !== 'pgsql') {
        throw new RuntimeException('The S6.4 persistence gate must use PostgreSQL.');
    }

    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The S6.4 PostgreSQL database does not match the guarded test database.');
    }
}

function s64PostgresNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-26 12:00:00+00:00');
}

function s64PostgresScope(
    string $connectionKey = 'tenant:postgres',
    string $prefix = 'fakturownia/finalized',
): ArtifactMaintenanceScope {
    return new ArtifactMaintenanceScope(
        $connectionKey,
        new ArtifactStorageNamespace('shared-artifacts', $prefix),
        DeploymentStage::Production,
        ArtifactStorageTopology::Shared,
    );
}

function s64PostgresObservation(string $expectedContents): ArtifactObjectObservation
{
    $address = ContentAddress::fromSha256(hash('sha256', $expectedContents));

    return new ArtifactObjectObservation(
        new ArtifactObjectDescriptor('shared-artifacts', $address, 'application/pdf', strlen($expectedContents)),
        s64PostgresNow()->modify('-48 hours'),
        hash('sha256', 'postgres-generation-1'),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function s64PostgresArtifactRow(string $contents, array $overrides = []): array
{
    $storageKeyCiphertext = 'encrypted-storage-key';
    $sha256 = hash('sha256', $contents);

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
        'content_address' => 'sha256:'.$sha256,
        'storage_key_version' => 1,
        'storage_key_cipher' => 'AES-256-GCM',
        'storage_key_ciphertext' => $storageKeyCiphertext,
        'storage_key_ciphertext_sha256' => hash('sha256', $storageKeyCiphertext),
        'content_sha256' => $sha256,
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($contents),
        'status' => 'ready',
        'created_at' => '2026-05-27 01:00:00.000000+00:00',
        'ready_at' => '2026-05-27 01:00:01.000000+00:00',
        'expires_at' => '2026-08-25 01:00:01.000000+00:00',
        'deleted_at' => null,
    ], $overrides);
}

it('enforces scoped artifact recovery and terminal tombstones in real PostgreSQL', function (): void {
    $databaseManager = app(DatabaseManager::class);

    $schema = s64PostgresSchemaName();
    $secondSchema = s64PostgresSchemaName();
    $lockSchema = s64PostgresSchemaName();
    $quotedSchema = s64PostgresQuotedIdentifier($schema);
    $quotedSecondSchema = s64PostgresQuotedIdentifier($secondSchema);
    $quotedLockSchema = s64PostgresQuotedIdentifier($lockSchema);
    $adminConfiguration = s64PostgresConfiguration();
    $testConfiguration = s64PostgresConfiguration($schema);
    $secondConfiguration = s64PostgresConfiguration($secondSchema);

    config()->set('database.connections.fakturownia_maintenance_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_maintenance_test', $testConfiguration);
    config()->set('database.connections.fakturownia_maintenance_second', $secondConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_maintenance_test');
    config()->set('integration-operations.database.schema', $schema);
    config()->set('fakturownia.connections.tenant:postgres.deployment_stage', 'production');
    config()->set('fakturownia.artifacts.connection', 'tenant:postgres');
    config()->set('fakturownia.artifacts.disk', 'shared-artifacts');
    config()->set('fakturownia.artifacts.prefix', 'fakturownia/finalized');
    config()->set('fakturownia.artifacts.storage_topology', 'shared');
    config()->set('fakturownia.artifacts.database_schema', $schema);
    config()->set('fakturownia.artifacts.lock_schema', $lockSchema);
    config()->set('fakturownia.artifacts.retention_days', 90);
    config()->set('fakturownia.artifacts.orphan_retention_hours', 24);
    config()->set('fakturownia.artifacts.maintenance_batch_size', 100);
    config()->set('fakturownia.artifacts.require_shared_storage_in_production', true);
    $databaseManager->purge('fakturownia_maintenance_admin');
    $databaseManager->purge('fakturownia_maintenance_test');
    $databaseManager->purge('fakturownia_maintenance_second');
    $admin = $databaseManager->connection('fakturownia_maintenance_admin');
    s64AssertPostgresDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");
    $admin->statement("CREATE SCHEMA {$quotedSecondSchema}");
    $admin->statement("CREATE SCHEMA {$quotedLockSchema}");

    try {
        $connection = $databaseManager->connection('fakturownia_maintenance_test');
        s64AssertPostgresDatabase($connection, $testConfiguration['database']);
        $exitCode = app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_maintenance_test',
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        expect($exitCode)->toBe(0);

        config()->set('integration-operations.database.connection', 'fakturownia_maintenance_second');
        config()->set('integration-operations.database.schema', $secondSchema);
        config()->set('fakturownia.artifacts.database_schema', $secondSchema);
        $secondConnection = $databaseManager->connection('fakturownia_maintenance_second');
        s64AssertPostgresDatabase($secondConnection, $secondConfiguration['database']);
        $secondExitCode = app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_maintenance_second',
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        expect($secondExitCode)->toBe(0);

        $firstAttestation = AttestedPostgresArtifactDatabase::attest(
            $connection,
            new SharedDatabaseArtifactLockConfiguration(
                'fakturownia_maintenance_test',
                $testConfiguration['database'],
                $testConfiguration['host'],
                $testConfiguration['port'],
                $schema,
                $lockSchema,
                'fakturownia_artifacts',
                'fakturownia_artifact_locks',
            ),
        );
        $secondAttestation = AttestedPostgresArtifactDatabase::attest(
            $secondConnection,
            new SharedDatabaseArtifactLockConfiguration(
                'fakturownia_maintenance_second',
                $secondConfiguration['database'],
                $secondConfiguration['host'],
                $secondConfiguration['port'],
                $secondSchema,
                $lockSchema,
                'fakturownia_artifacts',
                'fakturownia_artifact_locks',
            ),
        );
        $crossSchemaAddress = ContentAddress::fromSha256(hash('sha256', 'cross-schema-lock'));
        $firstSchemaLease = $firstAttestation->addressLock(30, 0)->acquire(s64PostgresScope()->storageNamespace, $crossSchemaAddress);

        expect(fn () => $secondAttestation->addressLock(30, 0)->acquire(
            s64PostgresScope()->storageNamespace,
            $crossSchemaAddress,
        ))->toThrow(LockTimeoutException::class);
        $firstSchemaLease->release();

        config()->set('integration-operations.database.connection', 'fakturownia_maintenance_test');
        config()->set('integration-operations.database.schema', $schema);
        config()->set('fakturownia.artifacts.database_schema', $schema);
        $repository = new DatabaseArtifactMaintenanceRepository($connection, $schema.'.fakturownia_artifacts');
        $storeFactory = new S64PostgresArtifactStoreFactory;
        $manager = (new PostgresArtifactMaintenanceManagerFactory(
            $databaseManager,
            config(),
            $storeFactory,
        ))->make();
        $store = $storeFactory->store;

        if (! $store instanceof S64PostgresArtifactStore) {
            throw new RuntimeException('The PostgreSQL artifact store factory was not initialized.');
        }

        $validArtifactConfiguration = config('fakturownia.artifacts');

        if (! is_array($validArtifactConfiguration)) {
            throw new RuntimeException('The PostgreSQL artifact configuration is unavailable.');
        }

        foreach ([
            ['fakturownia.artifacts.connection', 'tenant:missing'],
            ['fakturownia.artifacts.disk', '../hostile-disk'],
            ['fakturownia.artifacts.prefix', '../hostile-prefix'],
            ['fakturownia.connections.tenant:postgres.deployment_stage', 'hostile-stage'],
            ['fakturownia.artifacts.storage_topology', 'hostile-topology'],
        ] as [$configurationKey, $hostileValue]) {
            config()->set('fakturownia.artifacts', $validArtifactConfiguration);
            config()->set('fakturownia.connections.tenant:postgres.deployment_stage', 'production');
            config()->set($configurationKey, $hostileValue);
            $hostileStoreFactory = new S64PostgresArtifactStoreFactory;

            expect(fn (): mixed => (new PostgresArtifactMaintenanceManagerFactory(
                $databaseManager,
                config(),
                $hostileStoreFactory,
            ))->make())->toThrow(InvalidArgumentException::class)
                ->and($hostileStoreFactory->makeCalls)->toBe(0);
        }

        config()->set('fakturownia.artifacts', $validArtifactConfiguration);
        config()->set('fakturownia.connections.tenant:postgres.deployment_stage', 'production');

        $mismatchedPort = $testConfiguration['port'] === 65_535 ? 65_534 : $testConfiguration['port'] + 1;
        expect(fn (): AttestedPostgresArtifactDatabase => AttestedPostgresArtifactDatabase::attest(
            $connection,
            new SharedDatabaseArtifactLockConfiguration(
                'fakturownia_maintenance_test',
                $testConfiguration['database'],
                $testConfiguration['host'],
                $mismatchedPort,
                $schema,
                $lockSchema,
                'fakturownia_artifacts',
                'fakturownia_artifact_locks',
            ),
        ))->toThrow(InvalidArgumentException::class);
        expect(fn (): AttestedPostgresArtifactDatabase => AttestedPostgresArtifactDatabase::attest(
            $connection,
            new SharedDatabaseArtifactLockConfiguration(
                'fakturownia_maintenance_test',
                $testConfiguration['database'],
                $testConfiguration['host'],
                $testConfiguration['port'],
                $secondSchema,
                $lockSchema,
                'fakturownia_artifacts',
                'fakturownia_artifact_locks',
            ),
        ))->toThrow(RuntimeException::class);
        $lock = $firstAttestation->addressLock(30, 0);

        $connection->statement("SET TIME ZONE 'Europe/Warsaw'");
        $dstBoundary = s64PostgresArtifactRow('dst-boundary', [
            'ready_at' => '2026-10-24 01:30:00.000000+00:00',
            'expires_at' => '2026-10-25 01:30:00.123456+00:00',
        ]);
        $connection->table('fakturownia_artifacts')->insert($dstBoundary);
        $beforeDstBoundary = $repository->expiredPage(
            s64PostgresScope(),
            new DateTimeImmutable('2026-10-25 01:30:00.123455+00:00'),
            null,
            10,
        );
        $atDstBoundary = $repository->expiredPage(
            s64PostgresScope(),
            new DateTimeImmutable('2026-10-25 01:30:00.123456+00:00'),
            null,
            10,
        );

        expect($beforeDstBoundary->records)->toBe([])
            ->and($atDstBoundary->records)->toHaveCount(1)
            ->and($repository->quarantine(s64PostgresScope(), $atDstBoundary->records[0]))->toBeTrue();

        $quarantinedBoundary = $repository->auditPage(s64PostgresScope(), null, 10)->records[0] ?? null;
        expect($quarantinedBoundary)->toBeInstanceOf(ArtifactMaintenanceRecord::class);

        if (! $quarantinedBoundary instanceof ArtifactMaintenanceRecord) {
            throw new RuntimeException('The DST boundary artifact was not quarantined.');
        }

        expect($repository->tombstone(
            s64PostgresScope(),
            $quarantinedBoundary,
            new DateTimeImmutable('2026-10-25 01:30:00.654321+00:00'),
        ))->toBeTrue();

        $storedDeletedAt = $connection->selectOne(
            "SELECT to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS deleted_at FROM fakturownia_artifacts WHERE id = ?",
            [$dstBoundary['id']],
        );
        expect($storedDeletedAt)->toBeInstanceOf(stdClass::class)
            ->and($storedDeletedAt->deleted_at ?? null)->toBe('2026-10-25 01:30:00.654321');

        expect(fn (): bool => $connection->table('fakturownia_artifacts')->insert(
            s64PostgresArtifactRow('invalid-prefix', ['storage_prefix' => '../escape']),
        ))->toThrow(QueryException::class);

        $isolated = s64PostgresArtifactRow('isolated-pdf', [
            'storage_prefix' => 'fakturownia/isolated',
        ]);
        $isolatedObservation = s64PostgresObservation('isolated-pdf');
        $connection->table('fakturownia_artifacts')->insert($isolated);
        $store->observations[(string) $isolatedObservation->object->contentAddress] = $isolatedObservation;
        $store->contents[(string) $isolatedObservation->object->contentAddress] = 'isolated-pdf';

        $wrongNamespaceReport = $manager->prune();
        expect($wrongNamespaceReport->examined)->toBe(0)
            ->and($connection->table('fakturownia_artifacts')->where('id', $isolated['id'])->value('status'))
            ->toBe('ready')
            ->and($store->observations)->toHaveKey((string) $isolatedObservation->object->contentAddress)
            ->and(fn (): int => $connection->table('fakturownia_artifacts')
                ->where('id', $isolated['id'])
                ->update(['storage_prefix' => 'fakturownia/finalized']))
            ->toThrow(QueryException::class);

        unset(
            $store->observations[(string) $isolatedObservation->object->contentAddress],
            $store->contents[(string) $isolatedObservation->object->contentAddress],
        );
        config()->set('fakturownia.artifacts.prefix', 'fakturownia/isolated');
        $isolatedStoreFactory = new S64PostgresArtifactStoreFactory;
        $isolatedManager = (new PostgresArtifactMaintenanceManagerFactory(
            $databaseManager,
            config(),
            $isolatedStoreFactory,
        ))->make();
        $isolatedStore = $isolatedStoreFactory->store;

        if (! $isolatedStore instanceof S64PostgresArtifactStore) {
            throw new RuntimeException('The isolated PostgreSQL artifact store factory was not initialized.');
        }

        $isolatedStore->observations[(string) $isolatedObservation->object->contentAddress] = $isolatedObservation;
        $isolatedStore->contents[(string) $isolatedObservation->object->contentAddress] = 'isolated-pdf';
        $isolatedReport = $isolatedManager->prune();
        config()->set('fakturownia.artifacts.prefix', 'fakturownia/finalized');
        expect($isolatedReport->objectsDeleted)->toBe(1)
            ->and($isolatedReport->tombstoned)->toBe(1);

        $crossPrefixTarget = s64PostgresArtifactRow('cross-prefix-pdf');
        $crossPrefixOther = s64PostgresArtifactRow('cross-prefix-pdf', [
            'connection_key' => 'tenant:other',
            'storage_prefix' => 'fakturownia/other',
            'expires_at' => '2026-09-25 01:00:01.000000+00:00',
        ]);
        $crossPrefixObservation = s64PostgresObservation('cross-prefix-pdf');
        $connection->table('fakturownia_artifacts')->insert($crossPrefixTarget);
        $connection->table('fakturownia_artifacts')->insert($crossPrefixOther);
        $store->observations[(string) $crossPrefixObservation->object->contentAddress] = $crossPrefixObservation;
        $store->contents[(string) $crossPrefixObservation->object->contentAddress] = 'cross-prefix-pdf';

        $crossPrefixReport = $manager->prune();
        expect($crossPrefixReport->objectsDeleted)->toBe(1)
            ->and($connection->table('fakturownia_artifacts')->where('id', $crossPrefixTarget['id'])->value('status'))
            ->toBe('deleted')
            ->and($connection->table('fakturownia_artifacts')->where('id', $crossPrefixOther['id'])->value('status'))
            ->toBe('ready');

        $valid = s64PostgresArtifactRow('valid-pdf');
        $validObservation = s64PostgresObservation('valid-pdf');
        $connection->table('fakturownia_artifacts')->insert($valid);
        $store->observations[(string) $validObservation->object->contentAddress] = $validObservation;
        $store->contents[(string) $validObservation->object->contentAddress] = 'valid-pdf';

        expect(fn (): int => $connection->table('fakturownia_artifacts')->where('id', $valid['id'])->update([
            'status' => 'deleted',
            'deleted_at' => '2026-08-26 12:00:00.000000+00:00',
        ]))->toThrow(QueryException::class)
            ->and($connection->table('fakturownia_artifacts')->where('id', $valid['id'])->value('status'))
            ->toBe('ready');

        $writerLease = $lock->acquire(
            s64PostgresScope('tenant:writer')->storageNamespace,
            $validObservation->object->contentAddress,
        );

        expect(fn (): mixed => $manager->prune())
            ->toThrow(LockTimeoutException::class)
            ->and($connection->table('fakturownia_artifacts')->where('id', $valid['id'])->value('status'))
            ->toBe('ready');

        $writerLease->release();
        $validReport = $manager->prune();

        expect($validReport->objectsDeleted)->toBe(1)
            ->and($validReport->tombstoned)->toBe(1)
            ->and($connection->table('fakturownia_artifacts')->where('id', $valid['id'])->value('status'))->toBe('deleted')
            ->and(fn (): int => $connection->table('fakturownia_artifacts')->where('id', $valid['id'])->update([
                'status' => 'ready',
                'deleted_at' => null,
            ]))->toThrow(QueryException::class);

        $descriptorCount = $connection->table('fakturownia_artifacts')->count();
        expect(fn (): int => $connection->table('fakturownia_artifacts')->where('id', $valid['id'])->delete())
            ->toThrow(QueryException::class)
            ->and(fn (): bool => $connection->statement("TRUNCATE TABLE {$quotedSchema}.\"fakturownia_artifacts\""))
            ->toThrow(QueryException::class)
            ->and($connection->table('fakturownia_artifacts')->count())->toBe($descriptorCount);

        $store->observations[(string) $validObservation->object->contentAddress] = $validObservation;
        $store->contents[(string) $validObservation->object->contentAddress] = 'valid-pdf';
        $residualReport = $manager->sweep();
        expect($residualReport->objectsDeleted)->toBe(1)
            ->and($connection->table('fakturownia_artifacts')->where('id', $valid['id'])->value('status'))
            ->toBe('deleted')
            ->and($store->observations)->not->toHaveKey((string) $validObservation->object->contentAddress);

        $missing = s64PostgresArtifactRow('missing-pdf');
        $connection->table('fakturownia_artifacts')->insert($missing);
        $missingReport = $manager->prune();
        expect($missingReport->findings[0]->issue)->toBe(ArtifactMaintenanceIssue::MissingObject)
            ->and($connection->table('fakturownia_artifacts')->where('id', $missing['id'])->value('status'))->toBe('quarantined')
            ->and($connection->table('fakturownia_artifacts')->where('id', $missing['id'])->value('deleted_at'))->toBeNull();

        $doctorReport = $manager->doctor();
        $doctorIssues = array_map(static fn ($finding) => $finding->issue, $doctorReport->findings);
        expect($doctorIssues)->toContain(ArtifactMaintenanceIssue::MissingObject)
            ->and($connection->table('fakturownia_artifacts')->where('id', $missing['id'])->value('status'))->toBe('quarantined');

        $secondMissingReport = $manager->prune();
        expect($secondMissingReport->tombstoned)->toBe(1)
            ->and($connection->table('fakturownia_artifacts')->where('id', $missing['id'])->value('status'))->toBe('deleted');

        $checksum = s64PostgresArtifactRow('expected');
        $checksumObservation = s64PostgresObservation('expected');
        $connection->table('fakturownia_artifacts')->insert($checksum);
        $store->observations[(string) $checksumObservation->object->contentAddress] = $checksumObservation;
        $store->contents[(string) $checksumObservation->object->contentAddress] = 'tampered';
        $checksumReport = $manager->prune();
        expect($checksumReport->findings[0]->issue)->toBe(ArtifactMaintenanceIssue::ChecksumMismatch)
            ->and($connection->table('fakturownia_artifacts')->where('id', $checksum['id'])->value('status'))->toBe('quarantined')
            ->and($connection->table('fakturownia_artifacts')->where('id', $checksum['id'])->value('deleted_at'))->toBeNull();

        $sharedTarget = s64PostgresArtifactRow('shared-pdf');
        $sharedOther = s64PostgresArtifactRow('shared-pdf', [
            'connection_key' => 'tenant:other',
            'expires_at' => '2026-09-25 01:00:01.000000+00:00',
        ]);
        $sharedObservation = s64PostgresObservation('shared-pdf');
        $connection->table('fakturownia_artifacts')->insert($sharedTarget);
        $connection->table('fakturownia_artifacts')->insert($sharedOther);
        $store->observations[(string) $sharedObservation->object->contentAddress] = $sharedObservation;
        $store->contents[(string) $sharedObservation->object->contentAddress] = 'shared-pdf';
        $sharedReport = $manager->prune();
        expect($sharedReport->tombstoned)->toBeGreaterThanOrEqual(1)
            ->and($connection->table('fakturownia_artifacts')->where('id', $sharedTarget['id'])->value('status'))->toBe('deleted')
            ->and($connection->table('fakturownia_artifacts')->where('id', $sharedOther['id'])->value('status'))->toBe('ready')
            ->and($store->observations)->toHaveKey((string) $sharedObservation->object->contentAddress);

        $retentionMismatch = s64PostgresArtifactRow('retention-mismatch', [
            'created_at' => '2026-08-20 01:00:00.000000+00:00',
            'ready_at' => '2026-08-20 01:00:01.000000+00:00',
            'expires_at' => '2026-08-25 01:00:01.000000+00:00',
        ]);
        $retentionMismatchObservation = s64PostgresObservation('retention-mismatch');
        $connection->table('fakturownia_artifacts')->insert($retentionMismatch);
        $store->observations[(string) $retentionMismatchObservation->object->contentAddress] = $retentionMismatchObservation;
        $store->contents[(string) $retentionMismatchObservation->object->contentAddress] = 'retention-mismatch';
        $retentionMismatchReport = $manager->prune();
        $retentionMismatchIssues = array_map(static fn ($finding) => $finding->issue, $retentionMismatchReport->findings);
        expect($retentionMismatchIssues)->toContain(ArtifactMaintenanceIssue::RetentionPolicyMismatch)
            ->and($connection->table('fakturownia_artifacts')->where('id', $retentionMismatch['id'])->value('status'))
            ->toBe('ready')
            ->and($store->observations)->toHaveKey((string) $retentionMismatchObservation->object->contentAddress);

        $orphan = s64PostgresObservation('rollback-orphan');
        $store->observations[(string) $orphan->object->contentAddress] = $orphan;
        $store->contents[(string) $orphan->object->contentAddress] = 'rollback-orphan';
        $unauthorizedPermit = ArtifactPurgePermitIssuer::create()->issueOrphan(
            s64PostgresScope()->storageNamespace,
            $orphan,
            new ArtifactPurgeDeadline(s64PostgresNow(), 10),
        );
        expect(fn () => $store->purgeOrphan(
            $unauthorizedPermit,
            s64PostgresScope()->storageNamespace,
            $orphan,
            new ArtifactPurgeDeadline(s64PostgresNow(), 10),
        ))->toThrow(ArtifactPurgeUnauthorized::class)
            ->and($store->observations)->toHaveKey((string) $orphan->object->contentAddress);

        $orphanReport = $manager->sweep();
        expect($orphanReport->objectsDeleted)->toBe(1)
            ->and($store->deleted)->toContain((string) $orphan->object->contentAddress)
            ->and($store->observations)->toHaveKey((string) $sharedObservation->object->contentAddress)
            ->and($store->observations)->toHaveKey((string) $checksumObservation->object->contentAddress);
    } finally {
        $databaseManager->purge('fakturownia_maintenance_test');
        $databaseManager->purge('fakturownia_maintenance_second');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $admin->statement("DROP SCHEMA {$quotedSecondSchema} CASCADE");
        $admin->statement("DROP SCHEMA {$quotedLockSchema} CASCADE");
        $databaseManager->purge('fakturownia_maintenance_admin');
    }
})->group('postgres');
