<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\DatabaseArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\InvoiceArtifactQuery;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Ulid;

final class S68ArtifactStream extends ArtifactContentStream
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function read(int $maximumBytes): string
    {
        $chunk = substr($this->bytes, $this->offset, $maximumBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function close(): void {}
}

final class S68ArtifactStore implements ContentAddressedArtifactStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        $bytes = '';

        while (! $content->eof()) {
            $bytes .= $content->read(1024);
        }

        $address = ContentAddress::fromSha256(hash('sha256', $bytes));
        $this->objects[(string) $address] = $bytes;

        return new ArtifactObjectDescriptor('shared-artifacts', $address, $mimeType, strlen($bytes));
    }

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor
    {
        $bytes = $this->objects[(string) $contentAddress] ?? null;

        return is_string($bytes)
            ? new ArtifactObjectDescriptor('shared-artifacts', $contentAddress, 'application/pdf', strlen($bytes))
            : null;
    }

    public function open(ContentAddress $contentAddress): ArtifactContentStream
    {
        $bytes = $this->objects[(string) $contentAddress] ?? null;

        if (! is_string($bytes)) {
            throw new RuntimeException('The PostgreSQL test object is absent.');
        }

        return new S68ArtifactStream($bytes);
    }
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s68PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)
        || getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1'
        || preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The invoice PDF PostgreSQL gate requires an opted-in test-only database.');
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

function s68SchemaName(): string
{
    return 'fakturownia_pdf_'.getmypid().'_'.bin2hex(random_bytes(5));
}

function s68QuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new RuntimeException('The invoice PDF test schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s68AssertDatabase(Connection $connection, string $expectedDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if ($connection->getDriverName() !== 'pgsql'
        || ! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The invoice PDF gate is connected to an unexpected database.');
    }
}

function s68Projection(S68ArtifactStore $store, string $marker = 'committed'): ArtifactProjectionPlan
{
    $bytes = "%PDF-1.7\n{$marker}\n%%EOF\n";
    $object = $store->put(new S68ArtifactStream($bytes), 'application/pdf');
    $revision = hash('sha256', "revision:{$marker}");

    return new ArtifactProjectionPlan(
        ArtifactId::fromRevisionHmac($revision),
        new ConnectionKey('sales'),
        new OperationId((string) new Ulid),
        new InvoiceResourceId((string) new Ulid),
        ArtifactType::InvoicePdf,
        $revision,
        hash('sha256', "snapshot:{$marker}"),
        new OperationId((string) new Ulid),
        "KSEF-2026-{$marker}",
        $object,
    );
}

it('projects, encrypts, scopes, streams, and rolls back durable PDF descriptors in PostgreSQL', function (): void {
    $databases = app(DatabaseManager::class);
    $schema = s68SchemaName();
    $quotedSchema = s68QuotedIdentifier($schema);
    $adminConfiguration = s68PostgresConfiguration();
    $testConfiguration = s68PostgresConfiguration($schema);

    config()->set('database.connections.fakturownia_pdf_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_pdf_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_pdf_test');
    config()->set('integration-operations.database.schema', $schema);
    config()->set('fakturownia.artifacts.disk', 'shared-artifacts');
    config()->set('fakturownia.artifacts.prefix', 'fakturownia/finalized');
    config()->set('fakturownia.artifacts.lock_schema', $schema);
    config()->set('fakturownia.artifacts.retention_days', 90);
    config()->set('fakturownia.artifacts.encryption.active_version', 1);
    config()->set('fakturownia.artifacts.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('a', 32)),
    ]);
    $databases->purge('fakturownia_pdf_admin');
    $databases->purge('fakturownia_pdf_test');
    $admin = $databases->connection('fakturownia_pdf_admin');
    s68AssertDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");

    try {
        $connection = $databases->connection('fakturownia_pdf_test');
        s68AssertDatabase($connection, $testConfiguration['database']);
        $packageMigrations = dirname(__DIR__, 3).'/database/migrations';

        expect(app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_pdf_test',
            '--path' => $packageMigrations,
            '--realpath' => true,
            '--force' => true,
        ]))->toBe(0);

        $objects = new S68ArtifactStore;
        app()->instance(ContentAddressedArtifactStore::class, $objects);
        app()->forgetInstance(DatabaseArtifactStore::class);
        $store = app(DatabaseArtifactStore::class);
        $plan = s68Projection($objects);
        $descriptor = $connection->transaction(fn () => $store->apply($plan));
        $replayed = $connection->transaction(fn () => $store->apply($plan));
        $row = $connection->table('fakturownia_artifacts')->where('id', $plan->artifactId->value)->first();

        expect($replayed)->toEqual($descriptor)
            ->and($descriptor->status)->toBe(ArtifactStatus::Ready)
            ->and($row)->toBeInstanceOf(stdClass::class)
            ->and($row->storage_key_cipher ?? null)->toBe('AES-256-GCM')
            ->and($row->storage_key_ciphertext ?? null)->not->toContain('fakturownia/finalized')
            ->and($row->source_gov_id_ciphertext ?? null)->not->toContain('KSEF-2026')
            ->and($row->expires_at ?? null)->not->toBeNull();

        $query = new InvoiceArtifactQuery(new ConnectionKey('sales'), $store, $objects);
        $stream = $query->open($plan->artifactId);
        $bytes = '';

        while (! $stream->eof()) {
            $bytes .= $stream->read(1024);
        }

        $stream->close();

        expect($bytes)->toContain('%PDF-1.7')
            ->and($query->findByOperation($plan->operationId))->toEqual($descriptor)
            ->and($query->findPdfByRevision($plan->resourceId, $plan->revisionKeyHmac))->toEqual($descriptor);

        $orphaned = s68Projection($objects, 'rolled-back');

        try {
            $connection->transaction(function () use ($store, $orphaned): void {
                $store->apply($orphaned);

                throw new RuntimeException('Force descriptor rollback after object completion.');
            });
        } catch (RuntimeException $failure) {
            expect($failure->getMessage())->toContain('Force descriptor rollback');
        }

        expect($store->find(new ConnectionKey('sales'), $orphaned->artifactId))->toBeNull()
            ->and($objects->inspect($orphaned->object->contentAddress))->toEqual($orphaned->object);
    } finally {
        $databases->purge('fakturownia_pdf_test');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $databases->purge('fakturownia_pdf_admin');
    }
})->group('postgres');
