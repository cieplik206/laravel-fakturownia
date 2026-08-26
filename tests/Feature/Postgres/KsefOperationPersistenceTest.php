<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Ksef\DatabaseKsefStateProjectionStore;
use Cieplik206\Fakturownia\Laravel\Resources\DatabaseInvoiceResourceStore;
use Cieplik206\Fakturownia\Stateful\Events\InvoiceKsefAccepted;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefState;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefValidationMode;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefSendTransport;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedCommand;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationFactory;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationFailure;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationHandler;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPollingStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResult;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Ulid;

final class S53ObservationReader implements KsefInvoiceObservationReader
{
    /** @var array<array-key, list<KsefInvoiceObservation>> */
    public array $observations = [];

    /** @var array<array-key, int> */
    public array $calls = [];

    public function observe(ConnectionKey $connectionKey, string $remoteId): KsefInvoiceObservation
    {
        $this->calls[$remoteId] = ($this->calls[$remoteId] ?? 0) + 1;
        $observation = array_shift($this->observations[$remoteId]);

        if (! $observation instanceof KsefInvoiceObservation) {
            throw new LogicException("The KSeF observation queue for {$remoteId} is empty.");
        }

        return $observation;
    }
}

final class S53SendTransport implements KsefSendTransport
{
    /** @var array<array-key, int> */
    public array $calls = [];

    /** @var list<string> */
    public array $loseResponseFor = [];

    public function transmitOnce(
        ConnectionKey $connectionKey,
        string $remoteId,
        EffectBoundary $boundary,
    ): KsefInvoiceObservation {
        $this->calls[$remoteId] = ($this->calls[$remoteId] ?? 0) + 1;
        $boundary->open();

        if (in_array($remoteId, $this->loseResponseFor, true)) {
            throw EnsureAcceptedOperationFailure::outcomeUnknown(ReconciliationTrigger::LostResponse);
        }

        return s53Observation($remoteId, 'processing');
    }
}

final class S53DurableAcceptanceNotifier implements DurableAcceptanceNotifier
{
    /** @var list<OperationReceipt> */
    public array $receipts = [];

    public function notify(OperationReceipt $receipt): void
    {
        $this->receipts[] = $receipt;
    }
}

final readonly class S53ResourceOperationView implements OperationView
{
    public function __construct(
        private OperationId $id,
        private CanonicalObject $canonicalPayload,
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
        return new OperationType('fakturownia.invoice.issue');
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:ksef:resource');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s53PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The KSeF PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1'
        || preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The KSeF PostgreSQL gate requires an opted-in test-only database.');
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

function s53SchemaName(): string
{
    return 'fakturownia_ksef_'.getmypid().'_'.bin2hex(random_bytes(5));
}

function s53QuotedIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('The KSeF test schema identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s53AssertDatabase(Connection $connection, string $expectedDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if ($connection->getDriverName() !== 'pgsql'
        || ! $current instanceof stdClass
        || ! is_string($current->database_name ?? null)
        || ! hash_equals($expectedDatabase, $current->database_name)) {
        throw new RuntimeException('The KSeF gate is connected to an unexpected database.');
    }
}

function s53Observation(
    string $remoteId,
    string $status,
    ?string $governmentId = null,
): KsefInvoiceObservation {
    return new KsefInvoiceObservation($remoteId, new OpenKsefStatus($status), $governmentId, 0);
}

function s53ResourcePlan(string $remoteId, string $reference): InvoiceResourceProjectionPlan
{
    $draft = InvoiceFixtures::draft();
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        InvoiceFixtures::scope(),
        "OID-{$reference}",
        $reference,
        OidUniquenessGate::notPassed(),
    );
    $operation = new S53ResourceOperationView(
        new OperationId((string) new Ulid),
        (new IssueInvoicePayloadCodec)->encode(new IssueInvoiceCommand($draft, $identity)),
    );
    $result = new IssueInvoiceResult(
        remoteId: $remoteId,
        number: "FV/2026/08/{$remoteId}",
        kind: $draft->kind,
        status: 'issued',
        issueDate: $draft->issueDate,
        buyerTaxNumber: '1234567890',
        totalGross: $draft->totalGross(),
        oid: "OID-{$reference}",
        positions: $draft->positions,
    );

    return (new IssueInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))->map($operation, $result);
}

function s53MakePollDue(Connection $connection, OperationId $operationId): void
{
    $connection->table('integration_operation_authoritative_states')
        ->where('operation_id', $operationId->value)
        ->update(['next_poll_at' => $connection->raw('CURRENT_TIMESTAMP')]);
}

function s53MakeRetryDue(Connection $connection, OperationId $operationId): void
{
    $connection->table('integration_operations')
        ->where('id', $operationId->value)
        ->update(['next_attempt_at' => $connection->raw('CURRENT_TIMESTAMP')]);
}

it('persists accepted KSeF state for explicit, lost-response, and provider-auto flows without duplicate sends', function (): void {
    $databaseManager = app(DatabaseManager::class);
    $configuration = app(Repository::class);
    $schema = s53SchemaName();
    $quotedSchema = s53QuotedIdentifier($schema);
    $adminConfiguration = s53PostgresConfiguration();
    $testConfiguration = s53PostgresConfiguration($schema);

    config()->set('database.connections.fakturownia_ksef_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_ksef_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_ksef_test');
    config()->set('integration-operations.database.schema', $schema);
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['invoice_resource']);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 1);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fakturownia',
        'connection' => 'sales',
        'operation_type' => EnsureAcceptedOperationDefinitionProvider::OperationType,
        'generation' => 1,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);
    config()->set('fakturownia.resources.encryption.active_version', 1);
    config()->set('fakturownia.resources.encryption.keys', [
        1 => 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
    ]);
    $databaseManager->purge('fakturownia_ksef_admin');
    $databaseManager->purge('fakturownia_ksef_test');
    $admin = $databaseManager->connection('fakturownia_ksef_admin');
    s53AssertDatabase($admin, $adminConfiguration['database']);
    $admin->statement("CREATE SCHEMA {$quotedSchema}");

    try {
        $connection = $databaseManager->connection('fakturownia_ksef_test');
        s53AssertDatabase($connection, $testConfiguration['database']);
        $kernelMigrations = dirname(__DIR__, 3).'/vendor/cieplik206/laravel-integration-operations/database/migrations';
        $packageMigrations = dirname(__DIR__, 3).'/database/migrations';

        expect(app(ConsoleKernel::class)->call('migrate', [
            '--database' => 'fakturownia_ksef_test',
            '--path' => $kernelMigrations,
            '--realpath' => true,
            '--force' => true,
        ]))->toBe(0)
            ->and(app(ConsoleKernel::class)->call('migrate', [
                '--database' => 'fakturownia_ksef_test',
                '--path' => $packageMigrations,
                '--realpath' => true,
                '--force' => true,
            ]))->toBe(0);

        $resourceStore = app(DatabaseInvoiceResourceStore::class);
        $plans = [
            '9001' => s53ResourcePlan('9001', 'ORDER-KSEF-EXPLICIT'),
            '9002' => s53ResourcePlan('9002', 'ORDER-KSEF-LOST'),
            '9003' => s53ResourcePlan('9003', 'ORDER-KSEF-AUTO'),
        ];

        foreach ($plans as $plan) {
            $connection->transaction(fn (): InvoiceResource => $resourceStore->apply($plan));
        }

        $reader = new S53ObservationReader;
        $reader->observations = [
            '9001' => [
                s53Observation('9001', 'not_sent'),
                s53Observation('9001', 'processing'),
                s53Observation('9001', 'ok', 'KSEF-2026-9001'),
            ],
            '9002' => [
                s53Observation('9002', 'not_sent'),
                s53Observation('9002', 'status_check_error'),
                s53Observation('9002', 'ok', 'KSEF-2026-9002'),
            ],
            '9003' => [
                s53Observation('9003', 'not_sent'),
                s53Observation('9003', 'ok', 'KSEF-2026-9003'),
            ],
        ];
        $transport = new S53SendTransport;
        $transport->loseResponseFor = ['9002'];
        app()->instance(KsefInvoiceObservationReader::class, $reader);
        app()->instance(KsefSendTransport::class, $transport);
        app()->forgetInstance(EnsureAcceptedPollingStrategy::class);
        app()->forgetInstance(EnsureAcceptedOperationHandler::class);
        app()->forgetInstance(AuthoritativeEnsureAcceptedReconciliationStrategy::class);
        app()->instance(DurableAcceptanceNotifier::class, new S53DurableAcceptanceNotifier);

        $acceptedEvents = [];
        app(Dispatcher::class)->listen(
            InvoiceKsefAccepted::class,
            function (InvoiceKsefAccepted $event) use (&$acceptedEvents): void {
                $acceptedEvents[] = $event->remoteId;
            },
        );

        $factory = app(EnsureAcceptedOperationFactory::class);
        $coordinator = app(OperationCoordinator::class);
        $processor = app(OperationProcessor::class);
        $scope = IntegrationScope::of('fakturownia', 'sales');
        $explicitProfile = KsefConnectionProfile::explicitSdk(
            str_repeat('a', 64),
            KsefValidationMode::BlockInvalid,
        );
        $autoProfile = KsefConnectionProfile::providerAutoSend(
            str_repeat('a', 64),
            KsefValidationMode::BlockInvalid,
            'buyer_company',
            true,
        );
        $receipts = [];

        foreach (['9001', '9002'] as $remoteId) {
            $receipts[$remoteId] = $coordinator->accept($factory->make(
                new EnsureAcceptedCommand(
                    new ConnectionKey('sales'),
                    $plans[$remoteId]->resourceId,
                    $remoteId,
                    $explicitProfile,
                ),
                IntegrationContext::make("workflow:ksef:{$remoteId}"),
            ));
        }

        $receipts['9003'] = $coordinator->accept($factory->make(
            new EnsureAcceptedCommand(
                new ConnectionKey('sales'),
                $plans['9003']->resourceId,
                '9003',
                $autoProfile,
            ),
            IntegrationContext::make('workflow:ksef:9003'),
        ));

        $definition = app(AuthoritativeDefinitionRegistry::class)->find(
            EnsureAcceptedOperationDefinitionProvider::provider(),
            new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType),
            1,
        );
        $resolvedExtensions = [];

        foreach ($definition?->extensionPoints() ?? [] as $extension) {
            $serviceId = $extension['reference']?->serviceId;

            if (is_string($serviceId) && app()->resolved($serviceId)) {
                $resolvedExtensions[] = $serviceId;
            }
        }

        expect($resolvedExtensions)->toBe([]);

        $processor->process($receipts['9001']->operationId);
        $processor->process($receipts['9001']->operationId);
        s53MakePollDue($connection, $receipts['9001']->operationId);
        $processor->process($receipts['9001']->operationId);
        s53MakePollDue($connection, $receipts['9001']->operationId);
        $processor->process($receipts['9001']->operationId);

        $processor->process($receipts['9002']->operationId);
        $processor->process($receipts['9002']->operationId);
        $lostAfterSend = $connection->table('integration_operations')
            ->where('id', $receipts['9002']->operationId->value)
            ->first();
        expect($lostAfterSend?->status)->toBe(OperationStatus::Uncertain->value)
            ->and($lostAfterSend?->last_error_category)->toBe('provider')
            ->and($lostAfterSend?->last_error_code)->toBe('fakturownia_ksef_outcome_unknown')
            ->and($lostAfterSend?->last_safe_failure_code)->toBe('fakturownia_ksef_outcome_unknown');
        s53MakeRetryDue($connection, $receipts['9002']->operationId);
        $processor->process($receipts['9002']->operationId);
        s53MakePollDue($connection, $receipts['9002']->operationId);
        $processor->process($receipts['9002']->operationId);

        $processor->process($receipts['9003']->operationId);
        s53MakePollDue($connection, $receipts['9003']->operationId);
        $processor->process($receipts['9003']->operationId);

        $query = app(AuthoritativeOperationQuery::class)->within($scope);
        $explicit = $query->find($receipts['9001']->operationId);
        $lostResponse = $query->find($receipts['9002']->operationId);
        $providerAuto = $query->find($receipts['9003']->operationId);

        expect($explicit?->status)->toBe(OperationStatus::Succeeded)
            ->and($explicit?->effectState)->toBe(EffectState::Applied)
            ->and($explicit?->terminalProofKind)->toBe(TerminalProofKind::Poll)
            ->and($explicit?->result)->toEqual(new EnsureAcceptedResult(
                '9001',
                'ok',
                KsefTerminalOutcome::Accepted,
                'KSEF-2026-9001',
            ))
            ->and($lostResponse?->status)->toBe(OperationStatus::Succeeded)
            ->and($lostResponse?->effectState)->toBe(EffectState::Applied)
            ->and($lostResponse?->terminalProofKind)->toBe(TerminalProofKind::Poll)
            ->and($providerAuto?->status)->toBe(OperationStatus::Succeeded)
            ->and($providerAuto?->effectState)->toBe(EffectState::NotStarted)
            ->and($transport->calls)->toBe(['9001' => 1, '9002' => 1])
            ->and($reader->calls)->toBe(['9001' => 3, '9002' => 3, '9003' => 2])
            ->and($acceptedEvents)->toBe(['9001', '9002', '9003']);

        foreach (['9001', '9002', '9003'] as $remoteId) {
            $state = app(DatabaseKsefStateProjectionStore::class)->find(
                new ConnectionKey('sales'),
                $plans[$remoteId]->resourceId,
            );

            expect($state)->toBeInstanceOf(InvoiceKsefState::class)
                ->and($state?->remoteId)->toBe($remoteId)
                ->and($state?->governmentId)->toBe("KSEF-2026-{$remoteId}")
                ->and($state?->status->raw)->toBe('ok');
        }

        expect($connection->table('fakturownia_invoice_ksef_states')->count())->toBe(3)
            ->and($connection->table('fakturownia_invoice_ksef_state_history')->count())->toBe(8)
            ->and($connection->table('integration_operation_attempts')
                ->where('operation_id', $receipts['9002']->operationId->value)
                ->where('mode', 'execute')
                ->count())->toBe(1);
    } finally {
        $databaseManager->purge('fakturownia_ksef_test');
        $admin->statement("DROP SCHEMA {$quotedSchema} CASCADE");
        $databaseManager->purge('fakturownia_ksef_admin');
    }
})->group('postgres');
