<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Resources\DatabaseInvoiceResourceStore;
use Cieplik206\Fakturownia\Laravel\Resources\SodiumInvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionCommand;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Resources\Exceptions\InvoiceResourceProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Tests\Support\Stateful\CorrectionFixtures;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Ulid;

final readonly class S48InvoiceResourceOperationView implements OperationView
{
    public function __construct(
        private OperationId $id,
        private CanonicalObject $canonicalPayload,
        private string $type = 'fakturownia.invoice.issue',
    ) {}

    public function operationId(): OperationId
    {
        return $this->id;
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType($this->type);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'workflow:invoice:resource');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s48PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The invoice resource PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1'
        || preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The invoice resource PostgreSQL gate requires an opted-in test-only database.');
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

function s48SchemaName(): string
{
    return 'fakturownia_resource_'.getmypid().'_'.bin2hex(random_bytes(5));
}

function s48QuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('The invoice resource test schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s48AssertDatabase(Connection $connection, string $expectedDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if ($connection->getDriverName() !== 'pgsql'
        || ! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The invoice resource gate is connected to an unexpected database.');
    }
}

function s48ProjectionPlan(
    string $operationId,
    string $transactionReference = 'ORDER-123',
    string $oid = 'OID-ORDER-123',
    string $remoteId = '9001',
): InvoiceResourceProjectionPlan {
    $draft = InvoiceFixtures::draft();
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        InvoiceFixtures::scope(),
        $oid,
        $transactionReference,
        OidUniquenessGate::notPassed(),
    );
    $operation = new S48InvoiceResourceOperationView(
        new OperationId($operationId),
        (new IssueInvoicePayloadCodec)->encode(new IssueInvoiceCommand($draft, $identity)),
    );
    $result = new IssueInvoiceResult(
        remoteId: $remoteId,
        number: 'FV/2026/08/1',
        kind: $draft->kind,
        status: 'issued',
        issueDate: $draft->issueDate,
        buyerTaxNumber: '1234567890',
        totalGross: $draft->totalGross(),
        oid: $oid,
        positions: $draft->positions,
    );

    return (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))->map($operation, $result);
}

function s48CorrectionProjectionPlan(string $operationId): InvoiceResourceProjectionPlan
{
    $draft = CorrectionFixtures::draft();
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        new RemoteIdentityScope(new ConnectionKey('sales'), 'correction', (string) $draft->departmentId),
        'OID-RETURN-123',
        'RETURN-123',
        OidUniquenessGate::notPassed(),
    );
    $operation = new S48InvoiceResourceOperationView(
        new OperationId($operationId),
        (new IssueCorrectionPayloadCodec)->encode(new IssueCorrectionCommand($draft, $identity)),
        IssueCorrectionOperationDefinitionProvider::OperationType,
    );
    $result = new IssueCorrectionResult(
        remoteId: 'correction-9001',
        sourceInvoiceId: $draft->sourceInvoiceId,
        number: 'KOR/2026/08/1',
        status: 'issued',
        totalGross: Money::fromDecimal('-50.00', 'PLN'),
    );

    return (new CorrectionResourceProjectionMapper(InvoiceFixtures::hmac()))->map($operation, $result);
}

it('projects and authenticates one idempotent invoice resource in real PostgreSQL', function (): void {
    $databaseManager = app(DatabaseManager::class);
    $configuration = app(Repository::class);
    $schema = s48SchemaName();
    $quotedSchema = s48QuotedIdentifier($schema);
    $adminConfiguration = s48PostgresConfiguration();
    $testConfiguration = s48PostgresConfiguration($schema);

    config()->set('database.connections.fakturownia_resource_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_resource_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_resource_test');
    config()->set('integration-operations.database.schema', $schema);
    config()->set('fakturownia.resources.encryption.active_version', 1);
    config()->set('fakturownia.resources.encryption.keys', [
        1 => 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
    ]);
    $databaseManager->purge('fakturownia_resource_admin');
    $databaseManager->purge('fakturownia_resource_test');
    $admin = $databaseManager->connection('fakturownia_resource_admin');
    s48AssertDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");

    try {
        $connection = $databaseManager->connection('fakturownia_resource_test');
        s48AssertDatabase($connection, $testConfiguration['database']);
        $exitCode = app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_resource_test',
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        $store = new DatabaseInvoiceResourceStore(
            new KernelDatabase($databaseManager, $configuration),
            new SodiumInvoiceResourceSnapshotProtector($configuration),
        );
        $plan = s48ProjectionPlan((string) new Ulid);

        expect($exitCode)->toBe(0)
            ->and(fn (): InvoiceResource => $store->apply($plan))
            ->toThrow(LogicException::class, 'kernel terminal transaction');

        $first = $connection->transaction(
            fn (): InvoiceResource => $store->apply($plan),
        );
        $replayed = $connection->transaction(
            fn (): InvoiceResource => $store->apply($plan),
        );

        expect($first->id->equals($plan->resourceId))->toBeTrue()
            ->and($replayed)->toEqual($first)
            ->and($connection->table('fakturownia_resources')->count())->toBe(1)
            ->and($connection->table('fakturownia_resource_local_lookups')->count())->toBe(1)
            ->and($store->findById($plan->connectionKey, $plan->resourceId))->toEqual($first)
            ->and($store->findByRemoteId($plan->connectionKey, '9001'))->toEqual($first)
            ->and($store->findByLocalReferenceDigests(
                $plan->connectionKey,
                InvoiceResource::LocalReferenceType,
                [$plan->localReferenceHmac],
            ))->toEqual($first)
            ->and($store->findByRemoteId(new ConnectionKey('other'), '9001'))->toBeNull()
            ->and(fn (): ?InvoiceResource => $store->findByLocalReferenceDigests(
                $plan->connectionKey,
                InvoiceResource::LocalReferenceType,
                [],
            ))->toThrow(LogicException::class);

        $correctionPlan = s48CorrectionProjectionPlan((string) new Ulid);
        $correction = $connection->transaction(
            fn (): InvoiceResource => $store->apply($correctionPlan),
        );

        expect($correction->localReferenceType)->toBe('customer_return')
            ->and($correction->snapshot)->toBeInstanceOf(IssueCorrectionResult::class)
            ->and($store->findByRemoteId($correctionPlan->connectionKey, 'correction-9001'))->toEqual($correction)
            ->and($store->findByLocalReferenceDigests(
                $correctionPlan->connectionKey,
                'customer_return',
                [$correctionPlan->localReferenceHmac],
            ))->toEqual($correction)
            ->and($connection->table('fakturownia_resources')->count())->toBe(2)
            ->and($connection->table('fakturownia_resource_local_lookups')->count())->toBe(2);

        $stored = $connection->table('fakturownia_resources')->where('id', $plan->resourceId->value)->first();
        expect($stored)->toBeInstanceOf(stdClass::class)
            ->and($stored->snapshot_cipher ?? null)->toBe('XCHACHA20-POLY1305')
            ->and($stored->snapshot_ciphertext ?? null)->not->toContain('FV/2026/08/1')
            ->and($stored->snapshot_ciphertext ?? null)->not->toContain('1234567890')
            ->and(hash('sha256', (string) ($stored->snapshot_ciphertext ?? '')))
            ->toBe($stored->snapshot_ciphertext_sha256 ?? null);

        $conflictingPlan = s48ProjectionPlan((string) new Ulid);
        expect(fn (): InvoiceResource => $connection->transaction(
            fn (): InvoiceResource => $store->apply($conflictingPlan),
        ))->toThrow(InvoiceResourceProjectionConflict::class);

        $rolledBackPlan = s48ProjectionPlan(
            (string) new Ulid,
            'ORDER-ROLLBACK',
            'OID-ORDER-ROLLBACK',
            '9002',
        );
        expect(fn (): mixed => $connection->transaction(function () use ($store, $rolledBackPlan): never {
            $store->apply($rolledBackPlan);

            throw new RuntimeException('force terminal transaction rollback');
        }))->toThrow(RuntimeException::class, 'force terminal transaction rollback')
            ->and($connection->table('fakturownia_resources')->count())->toBe(2);

        $ciphertext = $stored->snapshot_ciphertext ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('The encrypted resource snapshot is unavailable for the tamper test.');
        }

        $tampered = ($ciphertext[0] === 'A' ? 'B' : 'A').substr($ciphertext, 1);
        expect($connection->table('fakturownia_resources')
            ->where('id', $plan->resourceId->value)
            ->update([
                'snapshot_ciphertext' => $tampered,
                'snapshot_ciphertext_sha256' => hash('sha256', $tampered),
                'row_version' => 2,
            ]))->toBe(1)
            ->and(fn (): ?InvoiceResource => $store->findById($plan->connectionKey, $plan->resourceId))
            ->toThrow(InvalidArgumentException::class, 'cannot be authenticated');
    } finally {
        $databaseManager->purge('fakturownia_resource_test');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $databaseManager->purge('fakturownia_resource_admin');
    }
})->group('postgres');
