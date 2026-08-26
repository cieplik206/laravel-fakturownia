<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Laravel\Reconciliation\ConfigInvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationCandidate;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationEngine;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationExpectation;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationOrigin;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationScan;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\IssueInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Config\Repository as ConfigRepository;

/** @param-out Rt4ProductionIoCalls $calls */
function rt4FailClosedStrategy(?Rt4ProductionIoCalls &$calls = null): IssueInvoiceReconciliationStrategy
{
    $calls = new Rt4ProductionIoCalls;

    return new IssueInvoiceReconciliationStrategy(
        new FakturowniaManager(
            new Rt4StrategyFailIfConnectionResolved($calls),
            new Rt4StrategyFailIfClientCreated($calls),
        ),
        rt4StrategyHmac(),
        rt4StrategyConfig(),
    );
}

function rt4StrategyManagerWithoutIo(): FakturowniaManager
{
    $calls = new Rt4ProductionIoCalls;

    return new FakturowniaManager(
        new Rt4StrategyFailIfConnectionResolved($calls),
        new Rt4StrategyFailIfClientCreated($calls),
    );
}

function rt4StrategyHmac(): HmacSha256
{
    return InvoiceFixtures::hmac();
}

function rt4StrategyConfig(): InvoiceReconciliationConfiguration
{
    return new ConfigInvoiceReconciliationConfiguration(new ConfigRepository([
        'fakturownia' => [
            'reconciliation' => [
                'visibility_window_seconds' => 300,
                'required_absent_confirmations' => 2,
                'maximum_candidates_per_scan' => 100,
                'minimum_absent_confirmation_interval_seconds' => 120,
                'maximum_remote_clock_skew_seconds' => 60,
            ],
        ],
    ]));
}

it('keeps exact provider observations fail closed until production contracts are frozen', function (): void {
    $outcome = rt4FailClosedStrategy($calls)->reconcile(new Rt4ReconciliationContext);

    expect($outcome->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($outcome->evidenceCode)->toBe('fakturownia.invoice.production_wiring_not_frozen')
        ->and($outcome->safeFailure)->toBeNull()
        ->and($outcome->operationResult)->toBeNull()
        ->and($calls->connectionResolutions)->toBe(0)
        ->and($calls->clientCreations)->toBe(0);
});

it('keeps independently confirmed absence fail closed until production contracts are frozen', function (): void {
    $outcome = rt4FailClosedStrategy($calls)->reconcile(
        new Rt4ReconciliationContext(observationNumber: 2),
    );

    expect($outcome->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($outcome->evidenceCode)->toBe('fakturownia.invoice.production_wiring_not_frozen')
        ->and($outcome->safeFailure)->toBeNull()
        ->and($outcome->operationResult)->toBeNull()
        ->and($calls->connectionResolutions)->toBe(0)
        ->and($calls->clientCreations)->toBe(0);
});

it('ignores caller-controlled diagnostic decisions at the production boundary', function (
    InvoiceReconciliationScan $scan,
    ReconciliationResult $expected,
    ?string $failureCode,
): void {
    $callerControlledDecision = [$scan, $expected, $failureCode];
    $outcome = rt4FailClosedStrategy($calls)->reconcile(new Rt4ReconciliationContext);

    expect($callerControlledDecision)->toHaveCount(3)
        ->and($outcome->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($outcome->evidenceCode)->toBe('fakturownia.invoice.production_wiring_not_frozen')
        ->and($outcome->safeFailure)->toBeNull()
        ->and($outcome->operationResult)->toBeNull()
        ->and($calls->connectionResolutions)->toBe(0)
        ->and($calls->clientCreations)->toBe(0);
})->with([
    'inconclusive' => [
        InvoiceReconciliationScan::incomplete(),
        ReconciliationResult::Inconclusive,
        null,
    ],
    'ambiguous' => [
        InvoiceReconciliationScan::complete(),
        ReconciliationResult::AmbiguousMatches,
        'fakturownia_invoice_ambiguous',
    ],
]);

it('stays fail closed for every base context until authoritative context is vendor-pinned', function (): void {
    $contexts = [
        new Rt4ReconciliationContext(provider: 'other'),
        new Rt4ReconciliationContext(operationType: 'fakturownia.invoice.cancel'),
        new Rt4ReconciliationContext(connection: 'another-account'),
        new Rt4ReconciliationContext(observationNumber: 2),
    ];

    foreach ($contexts as $context) {
        $strategy = rt4FailClosedStrategy($calls);
        $outcome = $strategy->reconcile($context);

        expect($outcome->result)->toBe(ReconciliationResult::Inconclusive)
            ->and($outcome->evidenceCode)->toBe('fakturownia.invoice.production_wiring_not_frozen')
            ->and($outcome->safeFailure)->toBeNull()
            ->and($outcome->operationResult)->toBeNull()
            ->and($calls->connectionResolutions)->toBe(0)
            ->and($calls->clientCreations)->toBe(0);
    }
});

it('exposes only manager construction and context reconciliation on production entrypoints', function (
    string $entrypoint,
): void {
    if (! class_exists($entrypoint)) {
        throw new RuntimeException("The reconciliation entrypoint {$entrypoint} is unavailable.");
    }

    $reflection = new ReflectionClass($entrypoint);
    $constructor = $reflection->getConstructor();
    $reconcile = $reflection->getMethod('reconcile');

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($constructor)->not->toBeNull()
        ->and($constructor?->isPublic())->toBeTrue()
        ->and($constructor?->getNumberOfParameters())->toBe(3)
        ->and((string) $constructor?->getParameters()[0]->getType())->toBe(FakturowniaManager::class)
        ->and((string) $constructor?->getParameters()[1]->getType())->toBe(HmacSha256::class)
        ->and((string) $constructor?->getParameters()[2]->getType())->toBe(InvoiceReconciliationConfiguration::class)
        ->and($reconcile->isPublic())->toBeTrue()
        ->and($reconcile->getNumberOfParameters())->toBe(1)
        ->and((string) $reconcile->getParameters()[0]->getType())->toBe(ReconciliationContext::class);
})->with([
    IssueInvoiceReconciliationStrategy::class,
    InvoiceReconciliationEngine::class,
]);

it('cannot ingest caller-built probe candidate scan policy expectation clock resolver factory or engine', function (): void {
    $expectation = new InvoiceReconciliationExpectation(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::businessOid(
            InvoiceFixtures::scope(),
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
        InvoiceReconciliationOrigin::LostResponse,
        new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
        1,
    );
    $candidate = new InvoiceReconciliationCandidate(
        InvoiceFixtures::scope(),
        true,
        new DateTimeImmutable('2026-08-26T10:00:30+00:00'),
        (new IssueInvoiceResponseMapper)->map(InvoiceFixtures::json('issue-vat-response.json')),
    );
    $hostileValues = [
        new Rt4StrategyReadProbe([InvoiceReconciliationScan::complete([$candidate])]),
        $candidate,
        InvoiceReconciliationScan::complete([$candidate]),
        new InvoiceReconciliationPolicy(1),
        $expectation,
        new Rt4StrategyFrozenClock(new DateTimeImmutable('2099-01-01T00:00:00+00:00')),
        new Rt4StaticExpectationResolver($expectation),
        new Rt4VerifiedInvoiceResultFactory,
        new InvoiceReconciliationEngine(
            rt4StrategyManagerWithoutIo(),
            rt4StrategyHmac(),
            rt4StrategyConfig(),
        ),
    ];

    foreach ([IssueInvoiceReconciliationStrategy::class, InvoiceReconciliationEngine::class] as $entrypoint) {
        $reflection = new ReflectionClass($entrypoint);

        foreach ($hostileValues as $hostileValue) {
            expect(fn (): object => $reflection->newInstance(
                $hostileValue,
                rt4StrategyHmac(),
                rt4StrategyConfig(),
            ))
                ->toThrow(TypeError::class);
        }
    }
});

it('pins the production call graph while keeping the provider scan unreachable', function (): void {
    $root = dirname(__DIR__, 5);
    $strategy = file_get_contents(
        $root.'/src/Stateful/Invoices/Reconciliation/IssueInvoiceReconciliationStrategy.php',
    );
    $engine = file_get_contents(
        $root.'/src/Stateful/Invoices/Reconciliation/InvoiceReconciliationEngine.php',
    );

    expect($strategy)->toBeString()
        ->toContain('$this->engine = new InvoiceReconciliationEngine($manager, $hmac, $configuration);')
        ->not->toContain('InvoiceReconciliationExpectationResolver')
        ->not->toContain('VerifiedInvoiceResultFactory')
        ->not->toContain('InvoiceReconciliationClock')
        ->not->toContain('InvoiceReconciliationPolicy')
        ->and($engine)->toBeString()
        ->toContain('$this->probe = new FakturowniaInvoiceReconciliationReadProbe($manager);')
        ->toContain('$this->probe->scan(')
        ->toContain('private static function decideSealedObservation(')
        ->toContain('private static function productionPreflight(')
        ->not->toContain('InvoiceReconciliationClock');
});

it('rejects cloning serialization and native unserialize bypass for production authority objects', function (
    string $entrypoint,
): void {
    if (! class_exists($entrypoint)) {
        throw new RuntimeException("The reconciliation entrypoint {$entrypoint} is unavailable.");
    }

    $object = (new ReflectionClass($entrypoint))->newInstance(
        rt4StrategyManagerWithoutIo(),
        rt4StrategyHmac(),
        rt4StrategyConfig(),
    );
    $payload = sprintf('O:%d:"%s":0:{}', strlen($entrypoint), $entrypoint);

    expect(fn (): object => clone $object)->toThrow(LogicException::class)
        ->and(fn (): string => serialize($object))->toThrow(LogicException::class)
        ->and(fn (): mixed => unserialize($payload))->toThrow(LogicException::class);
})->with([
    IssueInvoiceReconciliationStrategy::class,
    InvoiceReconciliationEngine::class,
]);

final readonly class Rt4StaticExpectationResolver
{
    public function __construct(private InvoiceReconciliationExpectation $expectation) {}

    public function resolve(ReconciliationContext $context): InvoiceReconciliationExpectation
    {
        return $this->expectation;
    }
}

final class Rt4StrategyReadProbe
{
    public int $scanCalls = 0;

    /** @param list<InvoiceReconciliationScan> $scans */
    public function __construct(private array $scans) {}

    public function scan(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): InvoiceReconciliationScan {
        $scan = $this->scans[$this->scanCalls] ?? null;
        $this->scanCalls++;

        return $scan ?? throw new LogicException('No strategy scan was queued.');
    }
}

final readonly class Rt4VerifiedInvoiceResultFactory
{
    public function make(object $match): never
    {
        throw new LogicException('A hostile result factory must be unreachable.');
    }
}

final readonly class Rt4ReconciledOperationResult
{
    public function __construct(public string $remoteId) {}

    public function resultType(): string
    {
        return 'fakturownia.invoice.issued';
    }
}

final readonly class Rt4ReconciliationContext implements ReconciliationContext
{
    public function __construct(
        private string $provider = 'fakturownia',
        private string $connection = 'sales',
        private string $operationType = 'fakturownia.invoice.issue',
        private int $observationNumber = 1,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of($this->provider, $this->connection);
    }

    public function operationType(): OperationType
    {
        return new OperationType($this->operationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make();
    }

    public function payload(): CanonicalObject
    {
        return CanonicalObject::empty();
    }

    public function observationNumber(): int
    {
        return $this->observationNumber;
    }
}

final readonly class Rt4StrategyFrozenClock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class Rt4ProductionIoCalls
{
    public int $connectionResolutions = 0;

    public int $clientCreations = 0;
}

final readonly class Rt4StrategyFailIfConnectionResolved implements ConnectionResolver
{
    public function __construct(private ?Rt4ProductionIoCalls $calls = null) {}

    public function resolve(ConnectionKey $connectionKey): ConnectionProfile
    {
        if ($this->calls instanceof Rt4ProductionIoCalls) {
            $this->calls->connectionResolutions++;
        }

        throw new LogicException('Fail-closed reconciliation must not resolve a Fakturownia connection.');
    }
}

final readonly class Rt4StrategyFailIfClientCreated implements ClientFactory
{
    public function __construct(private ?Rt4ProductionIoCalls $calls = null) {}

    public function make(ConnectionConfig $connectionConfig): FakturowniaClient
    {
        if ($this->calls instanceof Rt4ProductionIoCalls) {
            $this->calls->clientCreations++;
        }

        throw new LogicException('Fail-closed reconciliation must not create a Fakturownia client.');
    }
}
