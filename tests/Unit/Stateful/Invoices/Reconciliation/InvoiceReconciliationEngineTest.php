<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\InvoiceFingerprint;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationCandidate;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationEngine;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationExpectation;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationOrigin;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationScan;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;

/** @param array<mixed> $previousAbsenceObservations */
function rt4ReconciliationExpectation(
    bool $withExactOid = true,
    InvoiceReconciliationOrigin $origin = InvoiceReconciliationOrigin::LostResponse,
    int $observationNumber = 1,
    array $previousAbsenceObservations = [],
): InvoiceReconciliationExpectation {
    $scope = InvoiceFixtures::scope();
    $identity = $withExactOid
        ? RemoteInvoiceIdentity::businessOid(
            $scope,
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        )
        : RemoteInvoiceIdentity::withoutRemoteUniqueness($scope);

    return new InvoiceReconciliationExpectation(
        InvoiceFixtures::draft(),
        $identity,
        $origin,
        new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
        $observationNumber,
        $previousAbsenceObservations,
    );
}

/** @param array<string, mixed> $overrides */
function rt4ReconciliationInvoice(array $overrides = []): IssuedInvoiceResult
{
    return (new IssueInvoiceResponseMapper)->map([
        ...InvoiceFixtures::json('issue-vat-response.json'),
        ...$overrides,
    ]);
}

function rt4ReconciliationCandidate(
    ?IssuedInvoiceResult $invoice = null,
    ?RemoteIdentityScope $scope = null,
    bool $income = true,
    string $remoteCreatedAt = '2026-08-26T10:00:30+00:00',
): InvoiceReconciliationCandidate {
    return new InvoiceReconciliationCandidate(
        $scope ?? InvoiceFixtures::scope(),
        $income,
        new DateTimeImmutable($remoteCreatedAt),
        $invoice ?? rt4ReconciliationInvoice(),
    );
}

function rt4ExactLocator(
    ?InvoiceReconciliationExpectation $expectation = null,
): ExactOidLocator {
    $identity = ($expectation ?? rt4ReconciliationExpectation())->identity;
    $oid = $identity->oid()
        ?? throw new LogicException('The test expectation must expose an OID.');

    return new ExactOidLocator($identity->scope, $oid);
}

/**
 * @return array{observation_number: int, observed_at: DateTimeImmutable, locator: ExactOidLocator, expected_fingerprint: VersionedHmacDigest}
 */
function rt4ConfirmedAbsence(
    int $observationNumber,
    string $observedAt,
    ?ExactOidLocator $locator = null,
): array {
    $expectation = rt4ReconciliationExpectation();

    return [
        'observation_number' => $observationNumber,
        'observed_at' => new DateTimeImmutable($observedAt),
        'locator' => $locator ?? rt4ExactLocator($expectation),
        'expected_fingerprint' => (new InvoiceFingerprint(InvoiceFixtures::hmac()))
            ->fromDraft($expectation->draft),
    ];
}

/**
 * @param  list<InvoiceReconciliationScan>  $scans
 *
 * @param-out Rt4QueuedInvoiceReadProbe $probe
 */
function rt4ReconciliationEngine(
    array $scans,
    ?Rt4QueuedInvoiceReadProbe &$probe = null,
    ?InvoiceReconciliationPolicy $policy = null,
    string $now = '2026-08-26T10:01:00+00:00',
): Rt4DiagnosticInvoiceReconciliationEngine {
    $probe = new Rt4QueuedInvoiceReadProbe($scans, remoteWritesBeforeReconciliation: 1);

    return new Rt4DiagnosticInvoiceReconciliationEngine(
        $probe,
        new InvoiceFingerprint(InvoiceFixtures::hmac()),
        $policy ?? new InvoiceReconciliationPolicy(300),
        new Rt4FrozenInvoiceReconciliationClock(new DateTimeImmutable($now)),
    );
}

it('recovers an exact invoice after an uncertain write with a read-only observation', function (
    InvoiceReconciliationOrigin $origin,
): void {
    $engine = rt4ReconciliationEngine([
        InvoiceReconciliationScan::complete([rt4ReconciliationCandidate()]),
    ], $probe);

    $decision = $engine->reconcileOfflineDecision(rt4ReconciliationExpectation(origin: $origin));

    expect($decision->result)->toBe(ReconciliationResult::FoundExact)
        ->and($decision->operationResult)->toBeInstanceOf(IssueInvoiceResult::class)
        ->and($decision->operationResult instanceof IssueInvoiceResult
            ? $decision->operationResult->remoteId
            : null)->toBe('380058094')
        ->and($probe->scanCalls)->toBe(1)
        ->and($probe->remoteReadCalls)->toBe(1)
        ->and($probe->remoteWrites)->toBe(1);
})->with([
    'provider success followed by a lost response' => [InvoiceReconciliationOrigin::LostResponse],
    'duplicate envelope followed by exact lookup' => [InvoiceReconciliationOrigin::DuplicateEnvelope],
]);

it('does not scan or write when OID uniqueness has not been verified', function (
    InvoiceReconciliationOrigin $origin,
): void {
    $engine = rt4ReconciliationEngine([
        InvoiceReconciliationScan::complete([rt4ReconciliationCandidate()]),
    ], $probe);

    $decision = $engine->reconcile(rt4ReconciliationExpectation(origin: $origin));

    expect($decision->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.oid_uniqueness_not_verified')
        ->and($probe->scanCalls)->toBe(0)
        ->and($probe->remoteReadCalls)->toBe(0)
        ->and($probe->remoteWrites)->toBe(1);
})->with([
    'lost response' => [InvoiceReconciliationOrigin::LostResponse],
    'duplicate envelope' => [InvoiceReconciliationOrigin::DuplicateEnvelope],
    'OID conflict' => [InvoiceReconciliationOrigin::OidConflict],
    'unknown provenance' => [InvoiceReconciliationOrigin::Unclassified],
]);

it('never treats another payload under the same OID as exact', function (): void {
    $differentPayload = rt4ReconciliationInvoice(['price_gross' => '101.00']);
    $engine = rt4ReconciliationEngine([
        InvoiceReconciliationScan::complete([rt4ReconciliationCandidate($differentPayload)]),
    ], $probe);

    $decision = $engine->reconcileOfflineDecision(rt4ReconciliationExpectation());

    expect($decision->result)->toBe(ReconciliationResult::AmbiguousMatches)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.identity_or_fingerprint_mismatch')
        ->and($decision->operationResult)->toBeNull()
        ->and($probe->scanCalls)->toBe(1)
        ->and($probe->remoteWrites)->toBe(1);
});

it('requires one candidate in the exact scope, time window, OID, and fingerprint', function (
    array $candidates,
): void {
    $engine = rt4ReconciliationEngine([
        InvoiceReconciliationScan::complete($candidates),
    ]);

    expect($engine->reconcileOfflineDecision(rt4ReconciliationExpectation())->result)
        ->toBe(ReconciliationResult::AmbiguousMatches);
})->with([
    'two otherwise exact candidates' => fn (): array => [
        rt4ReconciliationCandidate(),
        rt4ReconciliationCandidate(),
    ],
    'another connection' => fn (): array => [
        rt4ReconciliationCandidate(scope: new RemoteIdentityScope(
            new ConnectionKey('another-account'),
            'vat',
            '376237',
        )),
    ],
    'another document kind' => fn (): array => [
        rt4ReconciliationCandidate(scope: new RemoteIdentityScope(
            new ConnectionKey('sales'),
            'receipt',
            '376237',
        )),
    ],
    'another department' => fn (): array => [
        rt4ReconciliationCandidate(scope: new RemoteIdentityScope(
            new ConnectionKey('sales'),
            'vat',
            '999999',
        )),
    ],
    'another income direction' => fn (): array => [
        rt4ReconciliationCandidate(income: false),
    ],
    'another OID' => fn (): array => [
        rt4ReconciliationCandidate(rt4ReconciliationInvoice(['oid' => 'ORDER-OTHER'])),
    ],
    'created before the effect boundary' => fn (): array => [
        rt4ReconciliationCandidate(remoteCreatedAt: '2026-08-26T09:58:59+00:00'),
    ],
    'created implausibly after the observation' => fn (): array => [
        rt4ReconciliationCandidate(remoteCreatedAt: '2026-08-26T10:02:01+00:00'),
    ],
]);

it('keeps absence inconclusive while visibility is open or a scan is incomplete', function (
    InvoiceReconciliationScan $scan,
    string $observedAt,
    string $evidenceCode,
): void {
    $engine = rt4ReconciliationEngine([$scan], now: $observedAt);
    $decision = $engine->reconcileOfflineDecision(rt4ReconciliationExpectation());

    expect($decision->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($decision->evidenceCode)->toBe($evidenceCode);
})->with([
    'visibility window open' => [
        InvoiceReconciliationScan::complete(),
        '2026-08-26T10:04:59+00:00',
        'fakturownia.invoice.visibility_window_open',
    ],
    'incomplete scan' => [
        InvoiceReconciliationScan::incomplete(),
        '2026-08-26T10:06:00+00:00',
        'fakturownia.invoice.scan_incomplete',
    ],
]);

it('does not read when the authoritative clock predates the effect boundary', function (): void {
    $engine = rt4ReconciliationEngine(
        [InvoiceReconciliationScan::complete([rt4ReconciliationCandidate()])],
        $probe,
        now: '2026-08-26T09:59:59+00:00',
    );
    $decision = $engine->reconcile(rt4ReconciliationExpectation());

    expect($decision->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.observation_before_effect_boundary')
        ->and($probe->scanCalls)->toBe(0)
        ->and($probe->remoteWrites)->toBe(1);
});

it('requires a persisted prior absence and performs exactly one read per observation', function (): void {
    $prior = rt4ConfirmedAbsence(1, '2026-08-26T10:05:01+00:00');
    $expectation = rt4ReconciliationExpectation(
        observationNumber: 2,
        previousAbsenceObservations: [$prior],
    );
    $engine = rt4ReconciliationEngine(
        [InvoiceReconciliationScan::complete()],
        $probe,
        now: '2026-08-26T10:07:01+00:00',
    );

    $decision = $engine->reconcileOfflineDecision($expectation);

    expect($decision->result)->toBe(ReconciliationResult::AbsentConclusive)
        ->and($probe->scanCalls)->toBe(1)
        ->and($probe->remoteReadCalls)->toBe(1)
        ->and($probe->remoteWrites)->toBe(1);
});

it('does not infer absence from one current empty read without durable history', function (): void {
    $engine = rt4ReconciliationEngine(
        [InvoiceReconciliationScan::complete()],
        $probe,
        now: '2026-08-26T10:07:01+00:00',
    );
    $decision = $engine->reconcileOfflineDecision(rt4ReconciliationExpectation(
        observationNumber: 2,
    ));

    expect($decision->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.absence_confirmation_pending')
        ->and($probe->scanCalls)->toBe(1);
});

it('rejects absence history that is stale, unspaced, nonconsecutive, or bound to another target', function (
    array $prior,
    string $currentObservedAt,
    string $evidenceCode,
): void {
    $expectation = rt4ReconciliationExpectation(
        observationNumber: 2,
        previousAbsenceObservations: [$prior],
    );
    $engine = rt4ReconciliationEngine(
        [InvoiceReconciliationScan::complete()],
        now: $currentObservedAt,
    );
    $decision = $engine->reconcileOfflineDecision($expectation);

    expect($decision->result)->toBe(ReconciliationResult::Inconclusive)
        ->and($decision->evidenceCode)->toBe($evidenceCode);
})->with([
    'confirmation before visibility' => [
        fn (): array => rt4ConfirmedAbsence(1, '2026-08-26T10:04:59+00:00'),
        '2026-08-26T10:07:01+00:00',
        'fakturownia.invoice.absence_history_before_visibility',
    ],
    'same snapshot generation' => [
        fn (): array => rt4ConfirmedAbsence(1, '2026-08-26T10:05:01+00:00'),
        '2026-08-26T10:05:02+00:00',
        'fakturownia.invoice.absence_history_not_spaced',
    ],
    'nonconsecutive observation number' => [
        fn (): array => rt4ConfirmedAbsence(7, '2026-08-26T10:05:01+00:00'),
        '2026-08-26T10:07:01+00:00',
        'fakturownia.invoice.absence_history_not_consecutive',
    ],
    'another OID target' => [
        fn (): array => rt4ConfirmedAbsence(
            1,
            '2026-08-26T10:05:01+00:00',
            new ExactOidLocator(InvoiceFixtures::scope(), 'ORDER-OTHER'),
        ),
        '2026-08-26T10:07:01+00:00',
        'fakturownia.invoice.absence_history_target_mismatch',
    ],
]);

it('never makes duplicate or OID-conflict provenance absent even after confirmations', function (
    InvoiceReconciliationOrigin $origin,
): void {
    $prior = rt4ConfirmedAbsence(1, '2026-08-26T10:05:01+00:00');
    $expectation = rt4ReconciliationExpectation(
        origin: $origin,
        observationNumber: 2,
        previousAbsenceObservations: [$prior],
    );
    $engine = rt4ReconciliationEngine(
        [InvoiceReconciliationScan::complete()],
        $probe,
        now: '2026-08-26T10:07:01+00:00',
    );
    $decision = $engine->reconcileOfflineDecision($expectation);

    expect($decision->result)->toBe(ReconciliationResult::AmbiguousMatches)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.origin_requires_manual_review')
        ->and($probe->scanCalls)->toBe(1)
        ->and($probe->remoteWrites)->toBe(1);
})->with([
    [InvoiceReconciliationOrigin::DuplicateEnvelope],
    [InvoiceReconciliationOrigin::OidConflict],
    [InvoiceReconciliationOrigin::Unclassified],
]);

it('fails closed without an exact semantic OID locator', function (): void {
    $engine = rt4ReconciliationEngine([], $probe);
    $decision = $engine->reconcile(rt4ReconciliationExpectation(withExactOid: false));

    expect($decision->result)->toBe(ReconciliationResult::AmbiguousMatches)
        ->and($decision->evidenceCode)->toBe('fakturownia.invoice.exact_identity_unavailable')
        ->and($probe->scanCalls)->toBe(0)
        ->and($probe->remoteWrites)->toBe(1);
});

it('exposes only a read capability to the reconciliation engine', function (): void {
    $readCapability = new ReflectionClass(Rt4DiagnosticInvoiceReadProbe::class);
    $preflight = new ReflectionMethod(InvoiceReconciliationEngine::class, 'productionPreflight');
    $decision = new ReflectionMethod(InvoiceReconciliationEngine::class, 'decideSealedObservation');

    expect(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $readCapability->getMethods(ReflectionMethod::IS_PUBLIC),
    ))->toBe(['scan'])
        ->and($preflight->isPrivate())->toBeTrue()
        ->and($preflight->isStatic())->toBeTrue()
        ->and($decision->isPrivate())->toBeTrue()
        ->and($decision->isStatic())->toBeTrue();
});

it('rejects float money before it can participate in fingerprint reconciliation', function (): void {
    expect(fn (): IssuedInvoiceResult => rt4ReconciliationInvoice(['price_gross' => 100.0]))
        ->toThrow(InvalidArgumentException::class, 'must not be a float');
});

it('permits conclusive absence only for lost-response provenance', function (): void {
    foreach (InvoiceReconciliationOrigin::cases() as $origin) {
        expect($origin->allowsConclusiveAbsence())
            ->toBe($origin === InvoiceReconciliationOrigin::LostResponse);
    }
});

it('requires UTC for effect boundaries and persisted absence observations', function (): void {
    expect(fn (): InvoiceReconciliationExpectation => new InvoiceReconciliationExpectation(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
        InvoiceReconciliationOrigin::LostResponse,
        new DateTimeImmutable('2026-08-26T12:00:00+02:00'),
        1,
    ))->toThrow(InvalidArgumentException::class, 'must use UTC')
        ->and(fn (): InvoiceReconciliationExpectation => new InvoiceReconciliationExpectation(
            InvoiceFixtures::draft(),
            RemoteInvoiceIdentity::businessOid(
                InvoiceFixtures::scope(),
                'ORDER-123',
                OidUniquenessGate::notPassed(),
            ),
            InvoiceReconciliationOrigin::LostResponse,
            new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
            2,
            [[
                'observation_number' => 1,
                'observed_at' => new DateTimeImmutable('2026-08-26T12:05:00+02:00'),
                'locator' => new ExactOidLocator(InvoiceFixtures::scope(), 'ORDER-123'),
                'expected_fingerprint' => (new InvoiceFingerprint(InvoiceFixtures::hmac()))
                    ->fromDraft(InvoiceFixtures::draft()),
            ]],
        ))->toThrow(InvalidArgumentException::class, 'history is invalid');
});

it('rejects native object transfer for every diagnostic authority object', function (): void {
    $expectation = rt4ReconciliationExpectation();
    $candidate = rt4ReconciliationCandidate();
    $objects = [
        $expectation,
        $candidate,
        InvoiceReconciliationScan::complete([$candidate]),
        new InvoiceReconciliationPolicy(300),
    ];

    foreach ($objects as $object) {
        $class = $object::class;
        $payload = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

        expect(fn (): object => clone $object)->toThrow(LogicException::class)
            ->and(fn (): string => serialize($object))->toThrow(LogicException::class)
            ->and(fn (): mixed => unserialize($payload))->toThrow(LogicException::class);
    }
});

it('bounds decision evidence and immutable policy dimensions', function (): void {
    expect(fn (): ReconciliationOutcome => ReconciliationOutcome::inconclusive('INVALID'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceReconciliationPolicy => new InvoiceReconciliationPolicy(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceReconciliationPolicy => new InvoiceReconciliationPolicy(1, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceReconciliationPolicy => new InvoiceReconciliationPolicy(1, 11))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): InvoiceReconciliationPolicy => new InvoiceReconciliationPolicy(1, 2, 1001))
        ->toThrow(InvalidArgumentException::class);
});

/** Test-only no-I/O capability that cannot enter a production constructor. */
interface Rt4DiagnosticInvoiceReadProbe
{
    public function scan(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): InvoiceReconciliationScan;
}

final class Rt4QueuedInvoiceReadProbe implements Rt4DiagnosticInvoiceReadProbe
{
    public int $scanCalls = 0;

    public int $remoteReadCalls = 0;

    public int $remoteWrites;

    /** @param list<InvoiceReconciliationScan> $scans */
    public function __construct(
        private array $scans,
        int $remoteWritesBeforeReconciliation,
    ) {
        $this->remoteWrites = $remoteWritesBeforeReconciliation;
    }

    public function scan(
        ExactOidLocator $locator,
        InvoiceReconciliationExpectation $expectation,
    ): InvoiceReconciliationScan {
        $scan = $this->scans[$this->scanCalls] ?? null;
        $this->scanCalls++;
        $this->remoteReadCalls++;

        if (! $scan instanceof InvoiceReconciliationScan) {
            throw new LogicException('No reconciliation scan was queued.');
        }

        return $scan;
    }
}

/** Test-only no-I/O harness that reflects the one private production decision atom. */
final readonly class Rt4DiagnosticInvoiceReconciliationEngine
{
    public function __construct(
        private Rt4DiagnosticInvoiceReadProbe $probe,
        private InvoiceFingerprint $fingerprint,
        private InvoiceReconciliationPolicy $policy,
        private Rt4FrozenInvoiceReconciliationClock $clock,
    ) {}

    public function reconcile(InvoiceReconciliationExpectation $expectation): ReconciliationOutcome
    {
        $observedAt = $this->clock->now();
        $preflight = new ReflectionMethod(InvoiceReconciliationEngine::class, 'productionPreflight');
        $decision = $preflight->invoke(null, $expectation, $observedAt);

        if ($decision instanceof ReconciliationOutcome) {
            return $decision;
        }

        $locator = $expectation->identity->exactLocator()
            ?? throw new LogicException('Diagnostic preflight must require an exact locator.');

        return $this->invokeSealedDecision(
            $expectation,
            $observedAt,
            $locator,
            $this->probe->scan($locator, $expectation),
        );
    }

    public function reconcileOfflineDecision(
        InvoiceReconciliationExpectation $expectation,
    ): ReconciliationOutcome {
        $oid = $expectation->identity->oid()
            ?? throw new LogicException('Offline decision tests require a semantic OID.');
        $locator = new ExactOidLocator($expectation->identity->scope, $oid);
        $observedAt = $this->clock->now();

        return $this->invokeSealedDecision(
            $expectation,
            $observedAt,
            $locator,
            $this->probe->scan($locator, $expectation),
        );
    }

    private function invokeSealedDecision(
        InvoiceReconciliationExpectation $expectation,
        DateTimeImmutable $observedAt,
        ExactOidLocator $locator,
        InvoiceReconciliationScan $scan,
    ): ReconciliationOutcome {
        $decision = new ReflectionMethod(InvoiceReconciliationEngine::class, 'decideSealedObservation');
        $outcome = $decision->invoke(
            null,
            $expectation,
            $observedAt,
            $locator,
            $scan,
            $this->fingerprint,
            $this->policy,
        );

        return $outcome instanceof ReconciliationOutcome
            ? $outcome
            : throw new LogicException('Private reconciliation decision returned an invalid outcome.');
    }
}

final readonly class Rt4FrozenInvoiceReconciliationClock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
