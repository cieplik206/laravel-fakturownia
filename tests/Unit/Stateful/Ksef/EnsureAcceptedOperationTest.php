<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Ksef\KsefConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefOwnership;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefValidationMode;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefSendTransport;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedCommand;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedObservationProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationFactory;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationHandler;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPollingStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResult;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResultCodec;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\PollingContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\Enums\PollResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Enums\WriteActivation;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use DateTimeImmutable;
use InvalidArgumentException;

final class S52ObservationReader implements KsefInvoiceObservationReader
{
    /** @var list<KsefInvoiceObservation> */
    public array $observations = [];

    public int $calls = 0;

    public function observe(ConnectionKey $connectionKey, string $remoteId): KsefInvoiceObservation
    {
        $this->calls++;
        $observation = array_shift($this->observations);

        if (! $observation instanceof KsefInvoiceObservation) {
            throw new LogicException('The test observation queue is empty.');
        }

        return $observation;
    }
}

final class S52EffectBoundary implements EffectBoundary
{
    public int $openCalls = 0;

    public function open(): void
    {
        $this->openCalls++;
    }

    public function wasOpened(): bool
    {
        return $this->openCalls > 0;
    }
}

final class S52SendTransport implements KsefSendTransport
{
    public int $calls = 0;

    public function transmitOnce(
        ConnectionKey $connectionKey,
        string $remoteId,
        EffectBoundary $boundary,
    ): KsefInvoiceObservation {
        $this->calls++;
        $boundary->open();

        return s52Observation('processing');
    }
}

final readonly class S52PollingContext implements PollingContext
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private PollPurpose $purpose = PollPurpose::Preflight,
        private DateTimeImmutable $startedAt = new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
        private DateTimeImmutable $deadlineAt = new DateTimeImmutable('2026-08-27T10:00:00+00:00'),
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:ksef:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }

    public function pollPurpose(): PollPurpose
    {
        return $this->purpose;
    }

    public function pollAttemptNumber(): int
    {
        return 1;
    }

    public function pollStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function pollDeadlineAt(): DateTimeImmutable
    {
        return $this->deadlineAt;
    }
}

final readonly class S52ReconciliationContext implements AuthoritativeReconciliationContext
{
    public function __construct(private CanonicalObject $canonicalPayload) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:ksef:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }

    public function observationNumber(): int
    {
        return 1;
    }

    public function effectPossiblyStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T10:00:00+00:00');
    }

    public function observationStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T10:01:00+00:00');
    }

    /** @return list<ReconciliationObservation> */
    public function priorObservations(): array
    {
        return [];
    }

    public function reconciliationTrigger(): ReconciliationTrigger
    {
        return ReconciliationTrigger::LostResponse;
    }
}

final readonly class S52Execution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private EffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:ksef:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }

    public function effectBoundary(): EffectBoundary
    {
        return $this->boundary;
    }
}

function s52Profile(KsefOwnership $ownership = KsefOwnership::ExplicitSdk): KsefConnectionProfile
{
    return match ($ownership) {
        KsefOwnership::ExplicitSdk => KsefConnectionProfile::explicitSdk(
            str_repeat('a', 64),
            KsefValidationMode::BlockInvalid,
        ),
        KsefOwnership::ProviderAutoSend => KsefConnectionProfile::providerAutoSend(
            str_repeat('a', 64),
            KsefValidationMode::BlockInvalid,
            'buyer_company',
            true,
        ),
    };
}

function s52Command(KsefOwnership $ownership = KsefOwnership::ExplicitSdk): EnsureAcceptedCommand
{
    return new EnsureAcceptedCommand(
        new ConnectionKey('sales'),
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        '9001',
        s52Profile($ownership),
    );
}

function s52Payload(KsefOwnership $ownership = KsefOwnership::ExplicitSdk): CanonicalObject
{
    return (new EnsureAcceptedPayloadCodec)->encode(s52Command($ownership));
}

function s52Observation(
    string $status,
    ?string $governmentId = null,
    int $errors = 0,
): KsefInvoiceObservation {
    return new KsefInvoiceObservation('9001', new OpenKsefStatus($status), $governmentId, $errors);
}

it('freezes disjoint explicit and provider-auto ownership into canonical activation slots', function (): void {
    $codec = new EnsureAcceptedPayloadCodec;
    $explicit = $codec->encode(s52Command());
    $automatic = $codec->encode(s52Command(KsefOwnership::ProviderAutoSend));
    $factory = new EnsureAcceptedOperationFactory;
    $accepted = $factory->make(s52Command(), IntegrationContext::make('workflow:ksef:1'));

    expect($codec->writeActivationSlot($explicit))->toBe(EnsureAcceptedPayloadCodec::ExplicitWriteActivationSlot)
        ->and($codec->writeActivationSlot($automatic))->toBe(EnsureAcceptedPayloadCodec::ObserveOnlyWriteActivationSlot)
        ->and($codec->decode($explicit))->toEqual(s52Command())
        ->and($accepted->intent->semanticSlot)->toBe('ksef.ensure_accepted')
        ->and($accepted->intent->localReference?->type)->toBe('invoice_resource')
        ->and($accepted->intent->localReference?->identifier())->toBe('01K3K8N8G8V3A6R5T4Y2W1Q9P7');

    $tampered = new CanonicalObject([
        ...$automatic->values,
        'write_activation_slot' => EnsureAcceptedPayloadCodec::ExplicitWriteActivationSlot,
    ]);

    expect(fn () => $codec->decode($tampered))->toThrow(InvalidArgumentException::class);
});

it('registers a canonical poll-first single-effect contract with observe-only support', function (): void {
    $definitions = iterator_to_array(AuthoritativeEnsureAcceptedOperationDefinitionProvider::definitions());
    $definition = $definitions[0] ?? null;

    expect($definition)->not->toBeNull();
    (new AuthoritativeOperationDefinitionValidator)->assertValid($definition);

    expect($definition->initialLane)->toBe(InitialOperationLane::Poll)
        ->and($definition->successEffectPolicy)->toBe(SuccessEffectPolicy::MayBeObservedExternally)
        ->and($definition->maximumRemoteWrites)->toBe(1)
        ->and($definition->writeActivation->requireWriteActivationSlot(
            EnsureAcceptedPayloadCodec::ExplicitWriteActivationSlot,
        ))->toBe(WriteActivation::PollSendRequired)
        ->and($definition->writeActivation->requireWriteActivationSlot(
            EnsureAcceptedPayloadCodec::ObserveOnlyWriteActivationSlot,
        ))->toBe(WriteActivation::Disabled)
        ->and($definition->polling?->deadlineSeconds)->toBe(86_400);
});

it('preflights explicit ownership, invokes exactly one boundary-opening send, then returns to polling', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('not_sent')];
    $preflight = (new EnsureAcceptedPollingStrategy($reader))->poll(new S52PollingContext(s52Payload()));
    $transport = new S52SendTransport;
    $boundary = new S52EffectBoundary;
    $execution = new S52Execution(s52Payload(), $boundary);
    $outcome = (new EnsureAcceptedOperationHandler($transport))->execute($execution);

    expect($preflight->result)->toBe(PollResult::SendRequired)
        ->and($transport->calls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1)
        ->and($outcome->requiresPolling)->toBeTrue();
});

it('keeps provider auto-send observe-only even when the remote status is not sent', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('not_sent')];
    $outcome = (new EnsureAcceptedPollingStrategy($reader))->poll(
        new S52PollingContext(s52Payload(KsefOwnership::ProviderAutoSend)),
    );

    expect($outcome->result)->toBe(PollResult::Wait)
        ->and($outcome->evidenceCode)->toBe('fakturownia.ksef.awaiting_provider_send');
});

it('maps accepted rejected processing offline unknown and deadline observations without resending', function (
    KsefInvoiceObservation $observation,
    PollResult $expected,
    string $evidence,
): void {
    $reader = new S52ObservationReader;
    $reader->observations = [$observation];
    $outcome = (new EnsureAcceptedPollingStrategy($reader))->poll(
        new S52PollingContext(s52Payload(), PollPurpose::Observation),
    );

    expect($outcome->result)->toBe($expected)
        ->and($outcome->evidenceCode)->toBe($evidence)
        ->and($outcome->providerObservation?->values['raw_status'] ?? null)->toBe($observation->status->raw);
})->with([
    'accepted' => [s52Observation('ok', 'KSEF-2026-9001'), PollResult::Completed, 'fakturownia.ksef.accepted'],
    'rejected' => [s52Observation('rejected', null, 1), PollResult::ProviderRejected, 'fakturownia.ksef.provider_rejected'],
    'processing' => [s52Observation('processing'), PollResult::Wait, 'fakturownia.ksef.processing'],
    'offline24' => [s52Observation('offline24'), PollResult::Wait, 'fakturownia.ksef.offline'],
    'unknown' => [s52Observation('future_provider_status'), PollResult::Wait, 'fakturownia.ksef.unknown_status'],
]);

it('marks the final safe observation overdue before the kernel deadline is exhausted', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('processing')];
    $outcome = (new EnsureAcceptedPollingStrategy($reader))->poll(new S52PollingContext(
        s52Payload(),
        PollPurpose::Observation,
        new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
        new DateTimeImmutable('2026-08-26T10:00:20+00:00'),
    ));

    expect($outcome->result)->toBe(PollResult::ManualReview)
        ->and($outcome->providerObservation?->values['overdue'] ?? null)->toBeTrue();
});

it('reconciles a lost response into polling once any nonterminal started status is observed', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('status_check_error')];
    $outcome = (new AuthoritativeEnsureAcceptedReconciliationStrategy($reader))->reconcile(
        new S52ReconciliationContext(s52Payload()),
    );

    expect($outcome->result)->toBe(AuthoritativeReconciliationResult::AppliedInProgress)
        ->and($outcome->operationResult)->toBeNull()
        ->and($outcome->providerObservation?->values['raw_status'] ?? null)->toBe('status_check_error');
});

it('never treats not-sent after a lost response as evidence permitting another send', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('not_sent')];
    $outcome = (new AuthoritativeEnsureAcceptedReconciliationStrategy($reader))->reconcile(
        new S52ReconciliationContext(s52Payload()),
    );

    expect($outcome->result)->toBe(AuthoritativeReconciliationResult::Inconclusive)
        ->and($outcome->evidenceCode)->toBe('fakturownia.ksef.send_not_confirmed');
});

it('round-trips typed accepted and rejected terminal results', function (): void {
    $codec = new EnsureAcceptedResultCodec;
    $accepted = new EnsureAcceptedResult('9001', 'ok', KsefTerminalOutcome::Accepted, 'KSEF-2026-9001');
    $rejected = new EnsureAcceptedResult('9001', 'not_applicable', KsefTerminalOutcome::NotApplicable, null);

    expect($codec->decode($codec->encode($accepted)))->toEqual($accepted)
        ->and($codec->decode($codec->encode($rejected)))->toEqual($rejected);
});

it('plans one idempotent current state and one append-only observation address', function (): void {
    $reader = new S52ObservationReader;
    $reader->observations = [s52Observation('processing')];
    $poll = (new EnsureAcceptedPollingStrategy($reader))->poll(
        new S52PollingContext(s52Payload(), PollPurpose::Observation),
    );
    $context = new S52PollingContext(s52Payload(), PollPurpose::Observation);
    $plan = (new EnsureAcceptedObservationProjectionPlanner)->plan(
        new ObservationProjectionInput($context, $poll),
    );

    expect($plan->mutations)->toHaveCount(2)
        ->and(array_column($plan->mutations, 'targetId'))->toBe([
            EnsureAcceptedObservationProjectionPlanner::HistoryTargetId,
            EnsureAcceptedObservationProjectionPlanner::StateTargetId,
        ]);
});
