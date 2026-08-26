<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionPayloadMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionRequestPayload;
use Cieplik206\Fakturownia\Stateful\Corrections\IssuedCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\Contracts\IssueCorrectionTransport;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\DisabledIssueCorrectionTransport;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionCommand;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationFactory;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationFailure;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationHandler;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Tests\Support\Stateful\CorrectionFixtures;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryDecision;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;

final class S72CorrectionBoundary implements EffectBoundary
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

final readonly class S72CorrectionExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private S72CorrectionBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P9');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'primary');
    }

    public function operationType(): OperationType
    {
        return new OperationType(IssueCorrectionOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'workflow:return:123');
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

final class S72RecordingCorrectionTransport implements IssueCorrectionTransport
{
    public int $calls = 0;

    public ?ConnectionKey $connection = null;

    public ?IssueCorrectionRequestPayload $payload = null;

    public function issue(
        ConnectionKey $connection,
        IssueCorrectionRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedCorrectionResult {
        $this->calls++;
        $this->connection = $connection;
        $this->payload = $payload;
        $boundary->open();

        return new IssuedCorrectionResult(
            'correction-9001',
            'source-123',
            'KOR/2026/08/1',
            'issued',
            Money::fromDecimal('-50.00', 'PLN'),
        );
    }
}

function s72CorrectionCommand(): IssueCorrectionCommand
{
    return new IssueCorrectionCommand(CorrectionFixtures::draft(), CorrectionFixtures::identity());
}

function s72CorrectionExecution(?S72CorrectionBoundary $boundary = null): S72CorrectionExecution
{
    return new S72CorrectionExecution(
        (new IssueCorrectionPayloadCodec)->encode(s72CorrectionCommand()),
        $boundary ?? new S72CorrectionBoundary,
    );
}

it('declares one effect-aware authoritative correction write with an atomic resource projection', function (): void {
    $definitions = iterator_to_array(
        AuthoritativeIssueCorrectionOperationDefinitionProvider::definitions(),
        false,
    );
    $definition = $definitions[0] ?? null;

    expect($definitions)->toHaveCount(1)
        ->and($definition)->not->toBeNull()
        ->and($definition?->provider->value)->toBe('fakturownia')
        ->and($definition?->operationType->value)->toBe(IssueCorrectionOperationDefinitionProvider::OperationType)
        ->and($definition?->versions->toArray())->toBe([
            'payload_schema' => 2,
            'handler' => 1,
            'result_schema' => 1,
        ])
        ->and($definition?->maximumRemoteWrites)->toBe(1)
        ->and($definition?->boundaryMode)->toBe(BoundaryMode::Required)
        ->and($definition?->safeRetryEvidence)->toBe(['request_not_started'])
        ->and($definition?->transportTargets[0]->targetTemplate)->toBe('/invoices.json')
        ->and($definition?->projection->targetIds)->toBe(['fakturownia.invoice_resource']);
});

it('builds a stable return intent and maps one canonical correction through one effect boundary', function (): void {
    $accepted = (new IssueCorrectionOperationFactory)->make(
        s72CorrectionCommand(),
        IntegrationContext::make(correlationId: 'workflow:return:123'),
        priority: 8,
    );
    $boundary = new S72CorrectionBoundary;
    $execution = s72CorrectionExecution($boundary);
    $transport = new S72RecordingCorrectionTransport;
    $outcome = (new IssueCorrectionOperationHandler($transport))->execute($execution);
    $body = $transport->payload?->bodyWithoutCredentials()['invoice'] ?? [];
    $result = $outcome->result;

    if (! $result instanceof IssueCorrectionResult) {
        throw new LogicException('Expected a typed issue correction result.');
    }

    expect($accepted->scope->connection->value)->toBe('primary')
        ->and($accepted->operationType->value)->toBe(IssueCorrectionOperationDefinitionProvider::OperationType)
        ->and($accepted->intent->resourceType)->toBe('invoice')
        ->and($accepted->intent->semanticSlot)->toBe('invoice.correction.issue')
        ->and($accepted->intent->localReference?->type)->toBe('customer_return')
        ->and($accepted->intent->localReference?->identifier())->toBe('return:123')
        ->and($accepted->priority)->toBe(8)
        ->and($transport->calls)->toBe(1)
        ->and($transport->connection?->value)->toBe('primary')
        ->and($boundary->openCalls)->toBe(1)
        ->and($body['oid'] ?? null)->toBe('0198ea14-e955-7ac1-b0c5-2b9397a90e51')
        ->and($body)->not->toHaveKey('oid_unique')
        ->and($result)->toBeInstanceOf(IssueCorrectionResult::class)
        ->and($result->remoteId())->toBe('correction-9001');
});

it('classifies correction failures without unsafe blind write retries', function (
    Throwable $failure,
    FailureDisposition $expectedDisposition,
    RetryDecision $expectedDecision,
    ReconciliationTrigger $expectedTrigger,
): void {
    $execution = s72CorrectionExecution();
    $classification = (new AuthoritativeIssueCorrectionFailureClassifier)->classify($execution, $failure);
    $retry = (new AuthoritativeIssueCorrectionRetryPolicy)->decide($execution, $classification);

    expect($classification->disposition)->toBe($expectedDisposition)
        ->and($classification->reconciliationTrigger)->toBe($expectedTrigger)
        ->and($retry->decision)->toBe($expectedDecision);
})->with([
    'request did not start' => [
        IssueCorrectionOperationFailure::requestNotStarted(),
        FailureDisposition::RequestNotStarted,
        RetryDecision::Retry,
        ReconciliationTrigger::Unknown,
    ],
    'lost response' => [
        IssueCorrectionOperationFailure::outcomeUnknown(ReconciliationTrigger::LostResponse),
        FailureDisposition::Uncertain,
        RetryDecision::Reconcile,
        ReconciliationTrigger::LostResponse,
    ],
    'provider rejection' => [
        IssueCorrectionOperationFailure::providerRejected(),
        FailureDisposition::NotApplied,
        RetryDecision::Fail,
        ReconciliationTrigger::Unknown,
    ],
    'capability unavailable' => [
        IssueCorrectionOperationFailure::capabilityUnavailable(),
        FailureDisposition::Permanent,
        RetryDecision::Fail,
        ReconciliationTrigger::Unknown,
    ],
    'unclassified failure' => [
        new RuntimeException('token=secret buyer@example.test'),
        FailureDisposition::ManualReview,
        RetryDecision::ManualReview,
        ReconciliationTrigger::Unknown,
    ],
]);

it('projects a correction only when its source identity and total match the canonical command', function (): void {
    $execution = s72CorrectionExecution();
    $result = new IssueCorrectionResult(
        'correction-9001',
        'source-123',
        'KOR/2026/08/1',
        'issued',
        Money::fromDecimal('-50.00', 'PLN'),
    );
    $plan = (new CorrectionResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map($execution, $result);
    $projection = (new IssueCorrectionOutcomeProjectionPlanner)->plan(
        new ProjectionInput(
            $execution,
            $result,
            new TerminalOutcomePair(
                OperationStatus::Succeeded,
                EffectState::Applied,
                ResultAvailability::Available,
                [TerminalProofKind::Execute],
            ),
        ),
    );

    expect($plan->resourceId->value)->toBe($execution->operationId()->value)
        ->and($plan->localReferenceType)->toBe('customer_return')
        ->and($plan->snapshot)->toBe($result)
        ->and($projection->mutations[0]->targetId)->toBe('fakturownia.invoice_resource')
        ->and(fn () => (new CorrectionResourceProjectionMapper(InvoiceFixtures::hmac()))->map(
            $execution,
            new IssueCorrectionResult(
                'correction-9001',
                'other-source',
                'KOR/2026/08/1',
                'issued',
                Money::fromDecimal('-50.00', 'PLN'),
            ),
        ))->toThrow(InvalidArgumentException::class);
});

it('keeps the package transport disabled until the consumer binds reviewed live transport', function (): void {
    $execution = s72CorrectionExecution();
    $transport = new DisabledIssueCorrectionTransport;
    $payload = (new IssueCorrectionPayloadCodec)->decode($execution->payload());

    expect(fn () => $transport->issue(
        $execution->scope()->connection,
        (new IssueCorrectionPayloadMapper)
            ->map($payload->draft, $payload->identity),
        $execution->effectBoundary(),
    ))->toThrow(IssueCorrectionOperationFailure::class);
});
