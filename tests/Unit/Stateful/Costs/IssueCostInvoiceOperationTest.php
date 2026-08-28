<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Costs\Operations\AuthoritativeIssueCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\DisabledIssueCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoicePayment;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\IssueCostInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final class CostEffectBoundary implements EffectBoundary
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

final readonly class CostOperationExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private CostEffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9C1');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType(IssueCostInvoiceOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'cost-import:123');
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

it('encodes a cost invoice under an isolated activation slot and identity', function (): void {
    $command = costCommand();
    $codec = new IssueCostInvoicePayloadCodec;
    $payload = $codec->encode($command);
    $accepted = (new IssueCostInvoiceOperationFactory)->make(
        $command,
        IntegrationContext::make(correlationId: 'cost-import:123'),
    );

    expect($payload->values['write_activation_slot'])->toBe(IssueCostInvoicePayloadCodec::WriteActivationSlot)
        ->and($payload->values['invoice']['income'])->toBeFalse()
        ->and($codec->decode($payload)->draft->income)->toBeFalse()
        ->and($accepted->operationType->value)->toBe(IssueCostInvoiceOperationFactory::OperationType)
        ->and($accepted->intent->semanticSlot)->toBe(IssueCostInvoiceOperationFactory::SemanticSlot)
        ->and($accepted->intent->localReference)->not->toBeNull()
        ->and($accepted->intent->localReference?->type)->toBe('cost_invoice')
        ->and(fn () => new IssueInvoiceCommand($command->draft, $command->identity))
        ->toThrow(InvalidArgumentException::class);

    $wrongIncome = $payload->values;
    $wrongIncome['invoice']['income'] = true;

    expect(fn () => $codec->decode(new CanonicalObject($wrongIncome)))
        ->toThrow(InvalidArgumentException::class);
});

it('registers a reconciled single-effect cost write while transport remains fail closed', function (): void {
    $regular = iterator_to_array(IssueCostInvoiceOperationDefinitionProvider::definitions(), false)[0];
    $authoritative = iterator_to_array(
        AuthoritativeIssueCostInvoiceOperationDefinitionProvider::definitions(),
        false,
    )[0];
    $boundary = new CostEffectBoundary;
    $execution = new CostOperationExecution(
        (new IssueCostInvoicePayloadCodec)->encode(costCommand()),
        $boundary,
    );

    expect($regular->operationType->value)->toBe(IssueCostInvoiceOperationFactory::OperationType)
        ->and($regular->maximumRemoteWrites)->toBe(1)
        ->and($authoritative->maximumRemoteWrites)->toBe(1)
        ->and($authoritative->boundaryMode)->toBe(BoundaryMode::Required)
        ->and($authoritative->safeRetryEvidence)->toBe(['request_not_started'])
        ->and(array_keys($authoritative->writeActivation->writeActivationSlots))->toBe([
            IssueCostInvoicePayloadCodec::WriteActivationSlot,
        ])
        ->and($authoritative->transportTargets[0]->targetTemplate)->toBe('/invoices.json')
        ->and(fn () => (new IssueCostInvoiceOperationHandler(
            new DisabledIssueCostInvoiceTransport,
        ))->execute($execution))
        ->toThrow(IssueInvoiceOperationFailure::class, 'not enabled by reviewed live evidence')
        ->and($boundary->openCalls)->toBe(0);
});

it('projects a cost resource under a cost-local reference without exposing the reference', function (): void {
    $command = costCommand();
    $operation = new CostOperationExecution(
        (new IssueCostInvoicePayloadCodec)->encode($command),
        new CostEffectBoundary,
    );
    $result = new IssueInvoiceResult(
        remoteId: '910123',
        number: 'FV/2026/08/123',
        kind: 'vat',
        status: 'issued',
        issueDate: $command->draft->issueDate,
        buyerTaxNumber: $command->draft->buyer->taxNumber,
        totalGross: Money::fromDecimal('123.00', 'PLN'),
        oid: 'COST-123',
        positions: $command->draft->positions,
    );
    $projection = (new IssueCostInvoiceResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map($operation, $result);

    expect($projection->localReferenceType)->toBe(InvoiceResource::CostLocalReferenceType)
        ->and($projection->connectionKey->value)->toBe('accounting')
        ->and(print_r($projection, true))->not->toContain('cost-draft:123');
});

function costCommand(): IssueCostInvoiceCommand
{
    $draft = new InvoiceDraft(
        kind: 'vat',
        income: false,
        sellDate: '2026-08-20',
        issueDate: '2026-08-26',
        departmentId: '50297',
        buyer: new InvoiceBuyer(
            company: true,
            name: 'Dostawca Sp. z o.o.',
            taxNumber: 'PL0000000000',
            postCode: '00-001',
            city: 'Warszawa',
            street: 'Przykładowa 1',
            country: 'PL',
            email: '',
            taxNumberKind: '',
        ),
        payment: new InvoicePayment(
            type: 'Przelew',
            status: 'issued',
            paid: Money::fromDecimal('0.00', 'PLN'),
            dueKind: 'off',
            paidDate: null,
            dueDate: '2026-09-09',
        ),
        description: 'Koszt testowy',
        positions: [new InvoiceLine(
            name: 'Usługa księgowa',
            tax: '23',
            totalGross: Money::fromDecimal('123.00', 'PLN'),
            quantity: '1',
        )],
        number: 'FV/2026/08/123',
    );
    $identity = RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
        new RemoteIdentityScope(new ConnectionKey('accounting'), 'vat', '50297'),
        'COST-123',
        'cost-draft:123',
        OidUniquenessGate::notPassed(),
    );

    return new IssueCostInvoiceCommand($draft, $identity);
}
