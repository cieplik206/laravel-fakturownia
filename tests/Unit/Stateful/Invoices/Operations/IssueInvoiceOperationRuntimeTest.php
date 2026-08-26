<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceRequestPayload;
use Cieplik206\Fakturownia\Stateful\Invoices\IssueInvoiceResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\Contracts\IssueInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
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
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;

final class S47EffectBoundary implements EffectBoundary
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

final readonly class S47IssueOperationExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private S47EffectBoundary $boundary,
        private IntegrationScope $integrationScope,
        private OperationType $type,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return $this->integrationScope;
    }

    public function operationType(): OperationType
    {
        return $this->type;
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'workflow:invoice:47');
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

final class S47RecordingIssueInvoiceTransport implements IssueInvoiceTransport
{
    public int $calls = 0;

    public ?ConnectionKey $connection = null;

    public ?IssueInvoiceRequestPayload $payload = null;

    public ?EffectBoundary $boundary = null;

    public function issue(
        ConnectionKey $connection,
        IssueInvoiceRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedInvoiceResult {
        $this->calls++;
        $this->connection = $connection;
        $this->payload = $payload;
        $this->boundary = $boundary;
        $boundary->open();

        return (new IssueInvoiceResponseMapper)->map(InvoiceFixtures::json('issue-vat-response.json'));
    }
}

function s47IssueExecution(?S47EffectBoundary $boundary = null): S47IssueOperationExecution
{
    $command = new IssueInvoiceCommand(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
            InvoiceFixtures::scope(),
            'OID-ORDER-123',
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
    );

    return new S47IssueOperationExecution(
        (new IssueInvoicePayloadCodec)->encode($command),
        $boundary ?? new S47EffectBoundary,
        IntegrationScope::of('fakturownia', 'sales'),
        new OperationType(IssueInvoiceOperationDefinitionProvider::OperationType),
    );
}

it('declares one effect-aware authoritative invoice write with an atomic resource projection', function (): void {
    $definitions = iterator_to_array(
        AuthoritativeIssueInvoiceOperationDefinitionProvider::definitions(),
        false,
    );
    $definition = $definitions[0] ?? null;

    expect($definitions)->toHaveCount(1)
        ->and($definition)->not->toBeNull()
        ->and($definition?->provider->value)->toBe('fakturownia')
        ->and($definition?->operationType->value)->toBe(IssueInvoiceOperationDefinitionProvider::OperationType)
        ->and($definition?->maximumRemoteWrites)->toBe(1)
        ->and($definition?->boundaryMode)->toBe(BoundaryMode::Required)
        ->and($definition?->safeRetryEvidence)->toBe(['request_not_started'])
        ->and($definition?->transportTargets)->toHaveCount(1)
        ->and($definition?->transportTargets[0]->method)->toBe('POST')
        ->and($definition?->transportTargets[0]->targetTemplate)->toBe('/invoices.json')
        ->and($definition?->projection->targetIds)->toBe(['fakturownia.invoice_resource']);

    $providerRejected = new TerminalOutcomePair(
        OperationStatus::Failed,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Reconcile],
    );

    expect($definition?->terminalOutcomes->allows($providerRejected, TerminalProofKind::Reconcile))
        ->toBeTrue();
});

it('maps a canonical operation through exactly one boundary-opening transport call', function (): void {
    $boundary = new S47EffectBoundary;
    $execution = s47IssueExecution($boundary);
    $transport = new S47RecordingIssueInvoiceTransport;
    $outcome = (new IssueInvoiceOperationHandler($transport))->execute($execution);

    expect($transport->calls)->toBe(1)
        ->and($transport->connection?->value)->toBe('sales')
        ->and($transport->boundary)->toBe($boundary)
        ->and($boundary->openCalls)->toBe(1)
        ->and($transport->payload?->bodyWithoutCredentials()['invoice']['oid'] ?? null)->toBe('OID-ORDER-123')
        ->and($transport->payload?->bodyWithoutCredentials()['invoice'] ?? [])->not->toHaveKey('oid_unique')
        ->and($outcome->result)->toBeInstanceOf(IssueInvoiceResult::class)
        ->and($outcome->result->resultType())->toBe('fakturownia.invoice.issue.result');
});

it('classifies every write outcome into an effect-safe authoritative retry decision', function (
    Throwable $failure,
    FailureDisposition $expectedDisposition,
    RetryDecision $expectedDecision,
    ReconciliationTrigger $expectedTrigger,
): void {
    $execution = s47IssueExecution();
    $classification = (new AuthoritativeIssueInvoiceFailureClassifier)->classify($execution, $failure);
    $retry = (new AuthoritativeIssueInvoiceRetryPolicy)->decide($execution, $classification);

    expect($classification->disposition)->toBe($expectedDisposition)
        ->and($classification->reconciliationTrigger)->toBe($expectedTrigger)
        ->and($retry->decision)->toBe($expectedDecision);
})->with([
    'request did not start' => [
        IssueInvoiceOperationFailure::requestNotStarted(),
        FailureDisposition::RequestNotStarted,
        RetryDecision::Retry,
        ReconciliationTrigger::Unknown,
    ],
    'lost response' => [
        IssueInvoiceOperationFailure::outcomeUnknown(ReconciliationTrigger::LostResponse),
        FailureDisposition::Uncertain,
        RetryDecision::Reconcile,
        ReconciliationTrigger::LostResponse,
    ],
    'provider rejection' => [
        IssueInvoiceOperationFailure::providerRejected(),
        FailureDisposition::NotApplied,
        RetryDecision::Fail,
        ReconciliationTrigger::Unknown,
    ],
    'capability unavailable' => [
        IssueInvoiceOperationFailure::capabilityUnavailable(),
        FailureDisposition::Permanent,
        RetryDecision::Fail,
        ReconciliationTrigger::Unknown,
    ],
    'manual review' => [
        IssueInvoiceOperationFailure::manualReviewRequired(),
        FailureDisposition::ManualReview,
        RetryDecision::ManualReview,
        ReconciliationTrigger::Unknown,
    ],
    'unclassified throwable' => [
        new RuntimeException('contains provider details'),
        FailureDisposition::ManualReview,
        RetryDecision::ManualReview,
        ReconciliationTrigger::Unknown,
    ],
]);

it('never leaks an unclassified throwable message into the safe failure', function (): void {
    $classification = (new AuthoritativeIssueInvoiceFailureClassifier)->classify(
        s47IssueExecution(),
        new RuntimeException('token=secret buyer@example.test'),
    );

    expect($classification->safeFailure)->toEqual(new SafeOperationFailure(
        'fakturownia_invoice_unclassified_failure',
        'The invoice operation failed without sufficient safe retry evidence.',
    ))
        ->and($classification->safeFailure->summary)->not->toContain('secret')
        ->and($classification->safeFailure->summary)->not->toContain('buyer@example.test');
});
