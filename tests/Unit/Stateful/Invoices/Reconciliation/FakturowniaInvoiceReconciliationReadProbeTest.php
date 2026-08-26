<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\FakturowniaInvoiceReconciliationReadProbe;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationCandidate;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationCandidateMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationEngine;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationExpectation;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationOrigin;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationScan;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\IssueInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rt4ExactProbePayload(array $overrides = []): array
{
    return [
        ...InvoiceFixtures::json('issue-vat-response.json'),
        'department_id' => '376237',
        'income' => true,
        'created_at' => '2026-08-26T12:00:30+02:00',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function rt4ExactProbeResponse(array $overrides = []): InvoiceResponseData
{
    return InvoiceResponseData::fromPayload(
        rt4ExactProbePayload($overrides),
        'invoice.read.exact_oid',
    );
}

function rt4ExactProbeExpectation(): InvoiceReconciliationExpectation
{
    return new InvoiceReconciliationExpectation(
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
}

it('maps a complete exact OID read DTO without rounding provider decimals', function (): void {
    $candidate = (new InvoiceReconciliationCandidateMapper)->map(
        new ExactOidLocator(InvoiceFixtures::scope(), 'ORDER-123'),
        InvoiceFixtures::draft(),
        rt4ExactProbeResponse(),
    );

    expect($candidate)->not->toBeNull()
        ->and($candidate?->scope->connection->equals(new ConnectionKey('sales')))->toBeTrue()
        ->and($candidate?->scope->documentKind)->toBe('vat')
        ->and($candidate?->scope->departmentId)->toBe('376237')
        ->and($candidate?->income)->toBeTrue()
        ->and($candidate?->remoteCreatedAt->format('Y-m-d\TH:i:sP'))->toBe('2026-08-26T10:00:30+00:00')
        ->and($candidate?->invoice->remoteId)->toBe('380058094')
        ->and($candidate?->invoice->totalGross->decimal())->toBe('100.00')
        ->and($candidate?->invoice->positions)->toHaveCount(2)
        ->and($candidate?->invoice->positions[0]->totalGross->decimal())->toBe('90.00');
});

it('fails closed instead of rounding exact read money beyond the draft scale', function (
    array $overrides,
): void {
    $candidate = (new InvoiceReconciliationCandidateMapper)->map(
        new ExactOidLocator(InvoiceFixtures::scope(), 'ORDER-123'),
        InvoiceFixtures::draft(),
        rt4ExactProbeResponse($overrides),
    );

    expect($candidate)->toBeNull();
})->with([
    'invoice total' => [['price_gross' => '100.001']],
    'position total' => [[
        'positions' => [[
            'name' => 'Produkt testowy [SKU-1]',
            'tax' => '23',
            'total_price_gross' => '90.001',
            'quantity' => '1',
            'quantity_unit' => 'szt.',
        ]],
    ]],
]);

it('fails closed for an incomplete exact read candidate', function (array $overrides): void {
    $candidate = (new InvoiceReconciliationCandidateMapper)->map(
        new ExactOidLocator(InvoiceFixtures::scope(), 'ORDER-123'),
        InvoiceFixtures::draft(),
        rt4ExactProbeResponse($overrides),
    );

    expect($candidate)->toBeNull();
})->with([
    'missing department' => [['department_id' => null]],
    'missing income direction' => [['income' => null]],
    'missing OID' => [['oid' => null]],
    'missing creation timestamp' => [['created_at' => null]],
    'missing invoice total' => [['price_gross' => null]],
    'missing currency' => [['currency' => null]],
    'missing position unit' => [[
        'positions' => [[
            'name' => 'Produkt testowy [SKU-1]',
            'tax' => '23',
            'total_price_gross' => '90.00',
            'quantity' => '1',
        ]],
    ]],
]);

it('rejects float money before a read DTO can become reconciliation evidence', function (): void {
    expect(fn (): InvoiceResponseData => rt4ExactProbeResponse(['price_gross' => 100.0]))
        ->toThrow(ProtocolViolation::class);
});

it('does not resolve credentials or perform a read without verified OID uniqueness', function (): void {
    $calls = new Rt4ExactProbeCalls;
    $manager = new FakturowniaManager(
        new Rt4FailIfConnectionResolved($calls),
        new Rt4FailIfClientCreated($calls),
    );
    $expectation = rt4ExactProbeExpectation();
    $oid = $expectation->identity->oid()
        ?? throw new LogicException('The fail-closed test expectation must expose an OID.');

    $scan = (new FakturowniaInvoiceReconciliationReadProbe($manager))->scan(
        new ExactOidLocator($expectation->identity->scope, $oid),
        $expectation,
    );

    expect($scan->complete)->toBeFalse()
        ->and($scan->candidates)->toBe([])
        ->and($calls->resolver)->toBe(0)
        ->and($calls->clientFactory)->toBe(0);
});

it('seals the concrete probe to the manager-owned exact read path', function (): void {
    $reflection = new ReflectionClass(FakturowniaInvoiceReconciliationReadProbe::class);
    $constructor = $reflection->getConstructor();

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($constructor)->not->toBeNull()
        ->and($constructor?->getNumberOfParameters())->toBe(1)
        ->and($constructor?->getParameters()[0]->getType()?->__toString())->toBe(FakturowniaManager::class);
});

it('rejects cloning serialization and native unserialize bypass for the concrete probe', function (): void {
    $calls = new Rt4ExactProbeCalls;
    $probe = new FakturowniaInvoiceReconciliationReadProbe(new FakturowniaManager(
        new Rt4FailIfConnectionResolved($calls),
        new Rt4FailIfClientCreated($calls),
    ));
    $class = FakturowniaInvoiceReconciliationReadProbe::class;
    $payload = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

    expect(fn (): object => clone $probe)->toThrow(LogicException::class)
        ->and(fn (): string => serialize($probe))->toThrow(LogicException::class)
        ->and(fn (): mixed => unserialize($payload))->toThrow(LogicException::class)
        ->and($calls->resolver)->toBe(0)
        ->and($calls->clientFactory)->toBe(0);
});

it('does not accept a hostile fake probe candidate or scan in a production entrypoint', function (): void {
    $expectation = rt4ExactProbeExpectation();
    $candidate = (new InvoiceReconciliationCandidateMapper)->map(
        new ExactOidLocator($expectation->identity->scope, 'ORDER-123'),
        $expectation->draft,
        rt4ExactProbeResponse(),
    ) ?? throw new LogicException('The hostile test requires a complete diagnostic candidate.');
    $hostileProbe = new Rt4HostileInvoiceReadProbe(
        InvoiceReconciliationScan::complete([$candidate]),
    );
    $resolver = new class($expectation)
    {
        public function __construct(private InvoiceReconciliationExpectation $expectation) {}

        public function resolve(ReconciliationContext $context): InvoiceReconciliationExpectation
        {
            return $this->expectation;
        }
    };
    $strategy = new ReflectionClass(IssueInvoiceReconciliationStrategy::class);
    $engine = new ReflectionClass(InvoiceReconciliationEngine::class);
    $forbiddenTypes = [
        'Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationReadProbe',
        'Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationExpectationResolver',
        'Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationClock',
        'Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\VerifiedInvoiceResultFactory',
        InvoiceReconciliationCandidate::class,
        InvoiceReconciliationScan::class,
        InvoiceReconciliationEngine::class,
    ];

    foreach ([$strategy, $engine] as $entrypoint) {
        $types = array_map(
            static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $entrypoint->getConstructor()?->getParameters() ?? [],
        );

        expect(array_intersect($forbiddenTypes, $types))->toBe([]);
    }

    expect(fn (): object => $strategy->newInstanceArgs([$resolver, $hostileProbe]))
        ->toThrow(TypeError::class)
        ->and(fn (): object => $engine->newInstanceArgs([$hostileProbe]))
        ->toThrow(TypeError::class);
});

it('pins the production call graph to the package-owned concrete exact read probe', function (): void {
    $root = dirname(__DIR__, 5);
    $strategy = file_get_contents(
        $root.'/src/Stateful/Invoices/Reconciliation/IssueInvoiceReconciliationStrategy.php',
    );
    $engine = file_get_contents(
        $root.'/src/Stateful/Invoices/Reconciliation/InvoiceReconciliationEngine.php',
    );

    expect($strategy)->toBeString()
        ->toContain('$this->engine = new InvoiceReconciliationEngine(')
        ->and($engine)->toBeString()
        ->toContain('$this->probe = new FakturowniaInvoiceReconciliationReadProbe($manager);');
});

final class Rt4ExactProbeCalls
{
    public int $resolver = 0;

    public int $clientFactory = 0;
}

final readonly class Rt4FailIfConnectionResolved implements ConnectionResolver
{
    public function __construct(private Rt4ExactProbeCalls $calls) {}

    public function resolve(ConnectionKey $connectionKey): ConnectionProfile
    {
        $this->calls->resolver++;

        throw new LogicException('The fail-closed reconciliation probe must not resolve a connection.');
    }
}

final readonly class Rt4FailIfClientCreated implements ClientFactory
{
    public function __construct(private Rt4ExactProbeCalls $calls) {}

    public function make(ConnectionConfig $connectionConfig): FakturowniaClient
    {
        $this->calls->clientFactory++;

        throw new LogicException('The fail-closed reconciliation probe must not create a client.');
    }
}

final readonly class Rt4HostileInvoiceReadProbe
{
    public function __construct(private InvoiceReconciliationScan $scan) {}

    public function scan(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): InvoiceReconciliationScan {
        return $this->scan;
    }
}
