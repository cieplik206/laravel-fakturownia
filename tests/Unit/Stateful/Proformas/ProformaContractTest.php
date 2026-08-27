<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\DisabledIssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaCommand;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationFactory;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationHandler;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaDraft;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayload;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayloadMapper;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\IssueProformaResourceProjectionMapper;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;

final class S82EffectBoundary implements EffectBoundary
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

final readonly class S82ProformaExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private S82EffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P8');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(IssueProformaOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make(correlationId: 'workflow:proforma:123');
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

it('maps a fixed-kind unpaid proforma to the synthetic credential-free contract fixture', function (): void {
    $fixture = s82ProformaRequestFixture();
    $draft = s82ProformaDraft();
    $payload = (new ProformaRequestPayloadMapper)->map($draft);
    $actual = [
        'authentication' => $payload->authenticationContract(),
        'headers' => $payload->headers(),
        'query' => $payload->query(),
        'body' => $payload->bodyWithoutCredentials(),
    ];
    $invoice = $actual['body']['invoice'];

    expect($fixture['contract'])->toBe('cieplik206.fakturownia.proforma-request-contract')
        ->and($fixture['version'])->toBe(1)
        ->and($fixture['evidence_status'])->toBe('synthetic_deferred_no_live_evidence')
        ->and($actual)->toBe($fixture['mapping'])
        ->and($invoice['kind'])->toBe('proforma')
        ->and($invoice['income'])->toBe('1')
        ->and($invoice['status'])->toBe('issued')
        ->and($invoice['paid'])->toBe('0.00')
        ->and($invoice['paid_date'])->toBeNull()
        ->and($invoice['payment_to_kind'])->toBe('14')
        ->and($invoice)->not->toHaveKeys([
            'currency',
            'oid',
            'oid_unique',
            'use_invoice_issuer',
        ]);

    array_walk_recursive($actual, static function (mixed $value): void {
        expect($value)->not->toBeFloat();
    });

    expect(print_r($draft, true))
        ->not->toContain('buyer@example.test')
        ->not->toContain('PL0000000000')
        ->and(print_r($payload, true))
        ->not->toContain('buyer@example.test')
        ->not->toContain('PL0000000000')
        ->and(fn (): string => serialize($draft))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($payload))->toThrow(LogicException::class);
});

it('keeps proforma payment policy package-owned and rejects invalid bounds', function (): void {
    $draft = s82ProformaDraft();
    $line = $draft->positions[0];

    expect($draft->payment->status)->toBe('issued')
        ->and($draft->payment->paid->decimal())->toBe('0.00')
        ->and($draft->payment->paidDate)->toBeNull()
        ->and($draft->payment->dueKind)->toBe('14')
        ->and($draft->payment->dueDate)->toBe('2026-09-09')
        ->and(fn () => s82ProformaDraft(paymentDueDate: '2026-02-30'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => s82ProformaDraft(positions: array_fill(0, 1001, $line)))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces the canonical plaintext body limit before any transport exists', function (): void {
    $line = new InvoiceLine(
        name: str_repeat('P', 300),
        tax: '23',
        totalGross: Money::fromDecimal('0.01', 'PLN'),
        quantity: '1',
    );
    $draft = s82ProformaDraft(positions: array_fill(0, 1000, $line));

    expect(fn () => ProformaRequestPayload::fromDraft($draft))
        ->toThrow(InvalidArgumentException::class, 'plaintext byte limit');
});

it('builds a separate fail-closed managed proforma intent without registering remote execution', function (): void {
    $draft = s82ProformaDraft();
    $identity = s82ProformaIdentity();
    $command = new IssueProformaCommand($draft, $identity);
    $codec = new IssueProformaPayloadCodec;
    $payload = $codec->encode($command);
    $accepted = (new IssueProformaOperationFactory)->make(
        $command,
        IntegrationContext::make(correlationId: 'workflow:proforma:123'),
    );
    $providerSource = file_get_contents(dirname(__DIR__, 4).'/src/Laravel/FakturowniaServiceProvider.php');

    expect($codec->writeActivationSlot($payload))->toBe(IssueProformaPayloadCodec::WriteActivationSlot)
        ->and($codec->decode($payload)->draft->toInvoiceDraft()->kind)->toBe('proforma')
        ->and($accepted->operationType->value)->toBe(IssueProformaOperationFactory::OperationType)
        ->and($accepted->intent->semanticSlot)->toBe(IssueProformaOperationFactory::SemanticSlot)
        ->and($providerSource)->toBeString()
        ->not->toContain('IssueProformaOperationFactory')
        ->not->toContain(IssueProformaOperationFactory::OperationType)
        ->and(fn () => new IssueInvoiceCommand($draft->toInvoiceDraft(), $identity))
        ->toThrow(InvalidArgumentException::class);

    $wrongSlot = $payload->values;
    $wrongSlot['write_activation_slot'] = 'invoice.vat.issue';

    expect(fn () => $codec->decode(new CanonicalObject($wrongSlot)))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the proforma runtime unregistered and fail-closed before its live gate', function (): void {
    $directory = dirname(__DIR__, 4).'/src/Stateful/Proformas';
    $source = '';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= (string) file_get_contents($file->getPathname());
        }
    }

    expect($source)
        ->not->toContain('Method::POST')
        ->not->toContain('->send(')
        ->not->toContain('Connector')
        ->not->toContain('OperationDefinitionProvider')
        ->toContain('OperationHandler')
        ->toContain('IssueProformaTransport');

    $identity = s82ProformaIdentity();
    $command = new IssueProformaCommand(s82ProformaDraft(), $identity);
    $payload = (new ProformaRequestPayloadMapper)->map($command->draft, $identity);
    $body = $payload->bodyWithoutCredentials()['invoice'];
    $boundary = new S82EffectBoundary;
    $execution = new S82ProformaExecution(
        (new IssueProformaPayloadCodec)->encode($command),
        $boundary,
    );

    expect($body['oid'] ?? null)->toBe('PROFORMA-123')
        ->and($body)->not->toHaveKey('oid_unique')
        ->and(fn () => (new IssueProformaOperationHandler(
            new DisabledIssueProformaTransport,
        ))->execute($execution))
        ->toThrow(
            IssueInvoiceOperationFailure::class,
            'not enabled by reviewed live evidence',
        )
        ->and($boundary->openCalls)->toBe(0);
});

it('prepares a typed proforma resource projection without registering the write', function (): void {
    $draft = s82ProformaDraft();
    $command = new IssueProformaCommand($draft, s82ProformaIdentity());
    $operation = new S82ProformaExecution(
        (new IssueProformaPayloadCodec)->encode($command),
        new S82EffectBoundary,
    );
    $result = new IssueInvoiceResult(
        remoteId: '900123',
        number: 'PRO/2026/08/123',
        kind: 'proforma',
        status: 'issued',
        issueDate: $draft->issueDate,
        buyerTaxNumber: $draft->buyer->taxNumber,
        totalGross: Money::fromDecimal('100.00', 'PLN'),
        oid: 'PROFORMA-123',
        positions: $draft->positions,
    );
    $projection = (new IssueProformaResourceProjectionMapper(InvoiceFixtures::hmac()))
        ->map($operation, $result);
    $plan = (new IssueProformaOutcomeProjectionPlanner)->plan(new ProjectionInput(
        $operation,
        $result,
        new TerminalOutcomePair(
            OperationStatus::Succeeded,
            EffectState::Applied,
            ResultAvailability::Available,
            [TerminalProofKind::Execute],
        ),
    ));

    expect($projection->connectionKey->value)->toBe('sales')
        ->and($projection->localReferenceType)->toBe('transaction_order')
        ->and($projection->snapshot)->toBe($result)
        ->and($plan->schemaVersion)->toBe(InvoiceResourceProjectionPlan::SchemaVersion)
        ->and($plan->mutations)->toHaveCount(1)
        ->and($plan->mutations[0]->targetId)->toBe(InvoiceResourceProjectionPlan::TargetId);
});

/**
 * @param  list<InvoiceLine>|null  $positions
 */
function s82ProformaDraft(
    ?array $positions = null,
    string $paymentDueDate = '2026-09-09',
): ProformaDraft {
    return new ProformaDraft(
        sellDate: '2026-08-20',
        issueDate: '2026-08-26',
        departmentId: '376237',
        buyer: new InvoiceBuyer(
            company: true,
            name: 'Example Buyer Sp. z o.o.',
            taxNumber: 'PL0000000000',
            postCode: '00-001',
            city: 'Warszawa',
            street: 'Przykładowa 1',
            country: 'PL',
            email: 'buyer@example.test',
            taxNumberKind: '',
        ),
        paymentType: 'Przelew',
        paymentDueDate: $paymentDueDate,
        description: 'Zamówienie testowe PROFORMA-123',
        positions: $positions ?? [
            new InvoiceLine(
                name: 'Produkt testowy [SKU-PRO-1]',
                tax: '23',
                totalGross: Money::fromDecimal('90.00', 'PLN'),
                quantity: '1',
            ),
            new InvoiceLine(
                name: 'Transport testowy',
                tax: '23',
                totalGross: Money::fromDecimal('10.00', 'PLN'),
                quantity: '1',
            ),
        ],
    );
}

function s82ProformaIdentity(): RemoteInvoiceIdentity
{
    return RemoteInvoiceIdentity::businessOid(
        new RemoteIdentityScope(new ConnectionKey('sales'), 'proforma', '376237'),
        'PROFORMA-123',
        OidUniquenessGate::notPassed(),
    );
}

/** @return array{contract: string, version: int, evidence_status: string, mapping: array<string, mixed>} */
function s82ProformaRequestFixture(): array
{
    $contents = file_get_contents(
        dirname(__DIR__, 3).'/Fixtures/Stateful/Proformas/proforma-request-contract.json',
    );
    $decoded = is_string($contents)
        ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
        : null;

    if (! is_array($decoded)) {
        throw new RuntimeException('The S8.2 proforma request fixture is invalid.');
    }

    /** @var array{contract: string, version: int, evidence_status: string, mapping: array<string, mixed>} $decoded */
    return $decoded;
}
