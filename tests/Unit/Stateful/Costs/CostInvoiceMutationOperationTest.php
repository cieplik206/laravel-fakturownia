<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\Contracts\DeleteCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DisabledDeleteCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Status\AuthoritativeChangeCostInvoiceStatusOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusCommand;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusResult;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusResultCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Status\Contracts\ChangeCostInvoiceStatusTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Status\DisabledChangeCostInvoiceStatusTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\RetryDecision;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;

final class S86CostEffectBoundary implements EffectBoundary
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

final readonly class S86CostOperationExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private S86CostEffectBoundary $boundary,
        private string $type,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9D8');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType($this->type);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('cost-mutation:123');
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

final class S86StatusTransport implements ChangeCostInvoiceStatusTransport
{
    public int $calls = 0;

    public function change(
        ConnectionKey $connection,
        string $remoteId,
        OpenInvoiceStatus $targetStatus,
        EffectBoundary $boundary,
    ): ChangeCostInvoiceStatusResult {
        $this->calls++;
        $boundary->open();

        return new ChangeCostInvoiceStatusResult($remoteId, $targetStatus);
    }
}

final class S87DeleteTransport implements DeleteCostInvoiceTransport
{
    public int $calls = 0;

    public function delete(
        ConnectionKey $connection,
        string $remoteId,
        EffectBoundary $boundary,
    ): DeleteCostInvoiceResult {
        $this->calls++;
        $boundary->open();

        return new DeleteCostInvoiceResult($remoteId);
    }
}

it('keeps cost invoice status open while isolating and reconciling the write', function (): void {
    $command = new ChangeCostInvoiceStatusCommand(
        new ConnectionKey('accounting'),
        '910123',
        'cost:123:status:custom-accounting-hold',
        new OpenInvoiceStatus('issued'),
        new OpenInvoiceStatus('custom_accounting_hold'),
        str_repeat('a', 64),
    );
    $codec = new ChangeCostInvoiceStatusPayloadCodec;
    $payload = $codec->encode($command);
    $accepted = (new ChangeCostInvoiceStatusOperationFactory)->make(
        $command,
        IntegrationContext::make('cost-status:123'),
    );
    $regular = iterator_to_array(ChangeCostInvoiceStatusOperationDefinitionProvider::definitions(), false)[0];
    $authoritative = iterator_to_array(
        AuthoritativeChangeCostInvoiceStatusOperationDefinitionProvider::definitions(),
        false,
    )[0];
    $boundary = new S86CostEffectBoundary;
    $transport = new S86StatusTransport;
    $outcome = (new ChangeCostInvoiceStatusOperationHandler($transport))->execute(
        new S86CostOperationExecution($payload, $boundary, ChangeCostInvoiceStatusOperationFactory::OperationType),
    );
    $encodedResult = (new ChangeCostInvoiceStatusResultCodec)->encode($outcome->result);
    $decodedResult = (new ChangeCostInvoiceStatusResultCodec)->decode($encodedResult);

    expect($codec->decode($payload)->targetStatus->known())->toBeNull()
        ->and($accepted->intent->localReference)->not->toBeNull()
        ->and($accepted->intent->localReference?->type)->toBe('cost_invoice_status_change')
        ->and($regular->operationType->value)->toBe(ChangeCostInvoiceStatusOperationFactory::OperationType)
        ->and($authoritative->boundaryMode)->toBe(BoundaryMode::Required)
        ->and($authoritative->transportTargets[0]->targetTemplate)->toBe('/invoices/{id}/change_status.json')
        ->and($transport->calls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1)
        ->and($decodedResult)->toEqual(new ChangeCostInvoiceStatusResult('910123', new OpenInvoiceStatus('custom_accounting_hold')))
        ->and((new OperationDefinitionValidator)->violations($regular))->toBe([])
        ->and((new AuthoritativeOperationDefinitionValidator)->violations($authoritative))->toBe([]);
});

it('keeps status transport disabled without opening the effect boundary', function (): void {
    $command = new ChangeCostInvoiceStatusCommand(
        new ConnectionKey('accounting'),
        '910123',
        'cost:123:status:paid',
        new OpenInvoiceStatus('issued'),
        new OpenInvoiceStatus('paid'),
        str_repeat('b', 64),
    );
    $boundary = new S86CostEffectBoundary;

    expect(fn () => (new ChangeCostInvoiceStatusOperationHandler(
        new DisabledChangeCostInvoiceStatusTransport,
    ))->execute(new S86CostOperationExecution(
        (new ChangeCostInvoiceStatusPayloadCodec)->encode($command),
        $boundary,
        ChangeCostInvoiceStatusOperationFactory::OperationType,
    )))->toThrow(IssueInvoiceOperationFailure::class)
        ->and($boundary->openCalls)->toBe(0);
});

it('requires an audited operator decision and never retries cost deletion automatically', function (): void {
    $command = new DeleteCostInvoiceCommand(
        new ConnectionKey('accounting'),
        '910123',
        'cost:123:delete',
        'pms-user:42',
        'accounting-cost-delete:123:2026-08-28T10:00:00+02:00',
        str_repeat('c', 64),
    );
    $codec = new DeleteCostInvoicePayloadCodec;
    $payload = $codec->encode($command);
    $accepted = (new DeleteCostInvoiceOperationFactory)->make(
        $command,
        IntegrationContext::make('cost-delete:123'),
    );
    $regular = iterator_to_array(DeleteCostInvoiceOperationDefinitionProvider::definitions(), false)[0];
    $authoritative = iterator_to_array(
        AuthoritativeDeleteCostInvoiceOperationDefinitionProvider::definitions(),
        false,
    )[0];
    $safeFailure = new SafeOperationFailure('test_request_not_started', 'The request was not started.');

    expect($codec->decode($payload))->toEqual($command)
        ->and($accepted->intent->localReference)->not->toBeNull()
        ->and($accepted->intent->localReference?->type)->toBe('cost_invoice_delete')
        ->and($regular->ambiguousEffectAction)->toBe(AmbiguousEffectAction::Reconcile)
        ->and($authoritative->ambiguousEffectAction)->toBe(AmbiguousEffectAction::Reconcile)
        ->and($authoritative->transportTargets[0]->method)->toBe('DELETE')
        ->and($authoritative->transportTargets[0]->targetTemplate)->toBe('/invoices/{id}.json')
        ->and((new DeleteCostInvoiceRetryPolicy)->decide(
            new S86CostOperationExecution($payload, new S86CostEffectBoundary, DeleteCostInvoiceOperationFactory::OperationType),
            new FailureClassification(FailureDisposition::RequestNotStarted, $safeFailure),
        )->decision)->toBe(RetryDecision::ManualReview)
        ->and((new AuthoritativeDeleteCostInvoiceRetryPolicy)->decide(
            new S86CostOperationExecution($payload, new S86CostEffectBoundary, DeleteCostInvoiceOperationFactory::OperationType),
            new ClassifiedFailure(FailureDisposition::RequestNotStarted, $safeFailure),
        )->decision)->toBe(RetryDecision::ManualReview)
        ->and((new OperationDefinitionValidator)->violations($regular))->toBe([])
        ->and((new AuthoritativeOperationDefinitionValidator)->violations($authoritative))->toBe([]);
});

it('opens exactly one delete boundary on success and none while disabled', function (): void {
    $command = new DeleteCostInvoiceCommand(
        new ConnectionKey('accounting'),
        '910123',
        'cost:123:delete',
        'pms-user:42',
        'accounting-cost-delete:123:2026-08-28T10:00:00+02:00',
        str_repeat('d', 64),
    );
    $payload = (new DeleteCostInvoicePayloadCodec)->encode($command);
    $boundary = new S86CostEffectBoundary;
    $transport = new S87DeleteTransport;
    $outcome = (new DeleteCostInvoiceOperationHandler($transport))->execute(
        new S86CostOperationExecution($payload, $boundary, DeleteCostInvoiceOperationFactory::OperationType),
    );
    $encoded = (new DeleteCostInvoiceResultCodec)->encode($outcome->result);

    expect($transport->calls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1)
        ->and((new DeleteCostInvoiceResultCodec)->decode($encoded))->toEqual(new DeleteCostInvoiceResult('910123'));

    $disabledBoundary = new S86CostEffectBoundary;

    expect(fn () => (new DeleteCostInvoiceOperationHandler(
        new DisabledDeleteCostInvoiceTransport,
    ))->execute(new S86CostOperationExecution(
        $payload,
        $disabledBoundary,
        DeleteCostInvoiceOperationFactory::OperationType,
    )))->toThrow(
        RuntimeException::class,
        'not enabled by reviewed live evidence',
    )->and($disabledBoundary->openCalls)->toBe(0);
});

it('rejects unaudited or stale-looking destructive intents', function (): void {
    expect(fn () => new DeleteCostInvoiceCommand(
        new ConnectionKey('accounting'),
        '910123',
        'cost:123:delete',
        '',
        'decision:123',
        str_repeat('e', 64),
    ))->toThrow(InvalidArgumentException::class, 'operator reference')
        ->and(fn () => new DeleteCostInvoiceCommand(
            new ConnectionKey('accounting'),
            '910123',
            'cost:123:delete',
            'pms-user:42',
            'decision:123',
            str_repeat('f', 63),
        ))->toThrow(InvalidArgumentException::class, 'snapshot HMAC');
});
