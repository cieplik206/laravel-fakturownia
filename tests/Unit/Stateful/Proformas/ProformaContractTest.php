<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaCommand;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationFactory;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaDraft;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayload;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayloadMapper;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

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

it('builds a separate fail-closed managed proforma intent without enabling remote execution', function (): void {
    $draft = s82ProformaDraft();
    $identity = RemoteInvoiceIdentity::businessOid(
        new RemoteIdentityScope(new ConnectionKey('sales'), 'proforma', '376237'),
        'PROFORMA-123',
        OidUniquenessGate::notPassed(),
    );
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

it('contains no proforma transport or operation definition registration surface', function (): void {
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
        ->not->toContain('OperationHandler')
        ->not->toContain('IssueInvoiceTransport')
        ->not->toContain('oid_unique');
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
