<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\OidUniquenessGate;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

it('round trips the canonical issue command with a fail closed identity gate', function (): void {
    $codec = new IssueInvoicePayloadCodec;
    $command = new IssueInvoiceCommand(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::businessOid(
            InvoiceFixtures::scope(),
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
    );
    $encoded = $codec->encode($command);
    $decoded = $codec->decode($encoded);

    expect($codec->writeActivationSlot($encoded))->toBe('invoice.vat.issue')
        ->and(IssueInvoicePayloadCodec::schemaVersion())->toBe(1)
        ->and(rt44IssueInvoicePayloadJson($codec->canonicalize($encoded)))
        ->toBe(rt44IssueInvoicePayloadJson($encoded))
        ->and($decoded->draft->kind)->toBe('vat')
        ->and($decoded->draft->income)->toBeTrue()
        ->and($decoded->draft->departmentId)->toBe('376237')
        ->and($decoded->draft->buyer->taxNumber)->toBe('123-456-78-90')
        ->and($decoded->draft->payment->paid->decimal())->toBe('0.00')
        ->and($decoded->draft->positions)->toHaveCount(2)
        ->and($decoded->identity->scope->connection->value)->toBe('sales')
        ->and($decoded->identity->policy)->toBe(RemoteIdentityPolicy::BusinessOid)
        ->and($decoded->identity->oid())->toBe('ORDER-123')
        ->and($decoded->identity->transactionOrderReference())->toBe('ORDER-123')
        ->and($decoded->identity->usesOidUnique())->toBeFalse()
        ->and($decoded->identity->exactLocator())->toBeNull();
});

it('round trips every supported identity policy without serializing uniqueness trust', function (): void {
    $codec = new IssueInvoicePayloadCodec;
    $scope = InvoiceFixtures::scope();
    $identities = [
        RemoteInvoiceIdentity::technicalOidWithTransactionOrder(
            $scope,
            'technical-oid',
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
        RemoteInvoiceIdentity::withoutRemoteUniqueness($scope),
    ];

    foreach ($identities as $identity) {
        $decoded = $codec->decode($codec->encode(new IssueInvoiceCommand(InvoiceFixtures::draft(), $identity)));

        expect($decoded->identity->policy)->toBe($identity->policy)
            ->and($decoded->identity->oid())->toBe($identity->oid())
            ->and($decoded->identity->transactionOrderReference())
            ->toBe($identity->transactionOrderReference())
            ->and($decoded->identity->usesOidUnique())->toBeFalse();
    }
});

it('rejects native command serialization and unsupported or mismatched issue commands', function (): void {
    $draft = InvoiceFixtures::draft();
    $command = new IssueInvoiceCommand(
        $draft,
        RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
    );
    $wrongScope = new RemoteIdentityScope(
        new ConnectionKey('sales'),
        'vat',
        '999',
    );

    expect(fn (): string => serialize($command))->toThrow(LogicException::class)
        ->and(fn (): IssueInvoiceCommand => new IssueInvoiceCommand(
            rt44IssueInvoicePayloadDraft($draft->positions, income: false),
            RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IssueInvoiceCommand => new IssueInvoiceCommand(
            rt44IssueInvoicePayloadDraft($draft->positions, kind: 'receipt'),
            RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IssueInvoiceCommand => new IssueInvoiceCommand(
            $draft,
            RemoteInvoiceIdentity::withoutRemoteUniqueness($wrongScope),
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects schema slot key scope and identity policy tampering', function (): void {
    $codec = new IssueInvoicePayloadCodec;
    $payload = $codec->encode(new IssueInvoiceCommand(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::businessOid(
            InvoiceFixtures::scope(),
            'ORDER-123',
            OidUniquenessGate::notPassed(),
        ),
    ))->values;

    $wrongSchema = $payload;
    $wrongSchema['schema_version'] = 2;

    $wrongSlot = $payload;
    $wrongSlot['write_activation_slot'] = 'fakturownia.invoice.delete';

    $unknownRoot = $payload;
    $unknownRoot['future'] = 'value';

    $wrongScope = $payload;
    $wrongScopeIdentity = rt44IssueInvoicePayloadMap($wrongScope['identity']);
    $wrongScopeIdentity['department_id'] = '999';
    $wrongScope['identity'] = $wrongScopeIdentity;

    $inconsistentBusiness = $payload;
    $inconsistentIdentity = rt44IssueInvoicePayloadMap($inconsistentBusiness['identity']);
    $inconsistentIdentity['transaction_order_reference'] = 'OTHER';
    $inconsistentBusiness['identity'] = $inconsistentIdentity;

    $forgedNone = $payload;
    $forgedNoneIdentity = rt44IssueInvoicePayloadMap($forgedNone['identity']);
    $forgedNoneIdentity['policy'] = RemoteIdentityPolicy::NoRemoteUniqueness->value;
    $forgedNone['identity'] = $forgedNoneIdentity;

    foreach ([$wrongSchema, $wrongSlot, $unknownRoot, $wrongScope, $inconsistentBusiness, $forgedNone] as $hostile) {
        expect(fn (): IssueInvoiceCommand => $codec->decode(new CanonicalObject($hostile)))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects ambiguous scalar and nested payload shapes', function (): void {
    $codec = new IssueInvoicePayloadCodec;
    $payload = $codec->encode(new IssueInvoiceCommand(
        InvoiceFixtures::draft(),
        RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
    ))->values;

    $stringIncome = $payload;
    $stringIncomeInvoice = rt44IssueInvoicePayloadMap($stringIncome['invoice']);
    $stringIncomeInvoice['income'] = '1';
    $stringIncome['invoice'] = $stringIncomeInvoice;

    $stringMoney = $payload;
    $stringMoneyInvoice = rt44IssueInvoicePayloadMap($stringMoney['invoice']);
    $stringMoneyPayment = rt44IssueInvoicePayloadMap($stringMoneyInvoice['payment']);
    $stringMoneyPaid = rt44IssueInvoicePayloadMap($stringMoneyPayment['paid']);
    $stringMoneyPaid['minor_units'] = '0';
    $stringMoneyPayment['paid'] = $stringMoneyPaid;
    $stringMoneyInvoice['payment'] = $stringMoneyPayment;
    $stringMoney['invoice'] = $stringMoneyInvoice;

    $unknownBuyer = $payload;
    $unknownBuyerInvoice = rt44IssueInvoicePayloadMap($unknownBuyer['invoice']);
    $unknownBuyerValue = rt44IssueInvoicePayloadMap($unknownBuyerInvoice['buyer']);
    $unknownBuyerValue['credential'] = 'forbidden';
    $unknownBuyerInvoice['buyer'] = $unknownBuyerValue;
    $unknownBuyer['invoice'] = $unknownBuyerInvoice;

    foreach ([$stringIncome, $stringMoney, $unknownBuyer] as $hostile) {
        expect(fn (): IssueInvoiceCommand => $codec->decode(new CanonicalObject($hostile)))
            ->toThrow(InvalidArgumentException::class);
    }

    $floatMoney = $payload;
    $floatMoneyInvoice = rt44IssueInvoicePayloadMap($floatMoney['invoice']);
    $floatMoneyPayment = rt44IssueInvoicePayloadMap($floatMoneyInvoice['payment']);
    $floatMoneyPaid = rt44IssueInvoicePayloadMap($floatMoneyPayment['paid']);
    $floatMoneyPaid['minor_units'] = 0.0;
    $floatMoneyPayment['paid'] = $floatMoneyPaid;
    $floatMoneyInvoice['payment'] = $floatMoneyPayment;
    $floatMoney['invoice'] = $floatMoneyInvoice;

    expect(fn (): CanonicalObject => new CanonicalObject($floatMoney))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts the exact kernel payload ceiling and rejects one byte more', function (): void {
    $codec = new IssueInvoicePayloadCodec;
    $maximumPayload = rt44IssueInvoicePayloadAtBytes(262_144);
    $oversized = $maximumPayload->values;
    $oversizedInvoice = rt44IssueInvoicePayloadMap($oversized['invoice']);
    $description = $oversizedInvoice['description'] ?? null;

    if (! is_string($description)) {
        throw new RuntimeException('The issue payload boundary fixture has no description.');
    }

    $oversizedInvoice['description'] = $description.'x';
    $oversized['invoice'] = $oversizedInvoice;
    $oversizedPayload = new CanonicalObject($oversized);

    expect(strlen(rt44IssueInvoicePayloadJson($maximumPayload)))->toBe(262_144)
        ->and(rt44IssueInvoicePayloadJson($codec->encode($codec->decode($maximumPayload))))
        ->toBe(rt44IssueInvoicePayloadJson($maximumPayload))
        ->and(strlen(rt44IssueInvoicePayloadJson($oversizedPayload)))->toBe(262_145)
        ->and(fn (): IssueInvoiceCommand => $codec->decode($oversizedPayload))
        ->toThrow(InvalidArgumentException::class);
});

/** @param list<InvoiceLine> $positions */
function rt44IssueInvoicePayloadDraft(array $positions, bool $income = true, string $kind = 'vat'): InvoiceDraft
{
    $draft = InvoiceFixtures::draft();

    return new InvoiceDraft(
        kind: $kind,
        income: $income,
        sellDate: $draft->sellDate,
        issueDate: $draft->issueDate,
        departmentId: $draft->departmentId,
        buyer: $draft->buyer,
        payment: $draft->payment,
        description: $draft->description,
        positions: $positions,
        number: $draft->number,
    );
}

function rt44IssueInvoicePayloadAtBytes(int $targetBytes): CanonicalObject
{
    $codec = new IssueInvoicePayloadCodec;
    $positions = array_fill(
        0,
        300,
        new InvoiceLine('P', '23', Money::fromDecimal('1.00', 'PLN'), '1'),
    );
    $base = $codec->encode(new IssueInvoiceCommand(
        rt44IssueInvoicePayloadDraft($positions),
        RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
    ));
    $remainingBytes = $targetBytes - strlen(rt44IssueInvoicePayloadJson($base));

    foreach ($positions as $index => $position) {
        if ($remainingBytes === 0) {
            break;
        }

        $addedBytes = min(1023, $remainingBytes);
        $positions[$index] = new InvoiceLine(
            'P'.str_repeat('x', $addedBytes),
            $position->tax,
            $position->totalGross,
            $position->quantity,
            $position->unit,
        );
        $remainingBytes -= $addedBytes;
    }

    if ($remainingBytes !== 0) {
        throw new RuntimeException('Unable to construct the exact issue payload codec boundary fixture.');
    }

    return $codec->encode(new IssueInvoiceCommand(
        rt44IssueInvoicePayloadDraft($positions),
        RemoteInvoiceIdentity::withoutRemoteUniqueness(InvoiceFixtures::scope()),
    ));
}

/** @return array<string, mixed> */
function rt44IssueInvoicePayloadMap(mixed $value): array
{
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException('The issue payload fixture value must be a map.');
    }

    return $value;
}

function rt44IssueInvoicePayloadJson(CanonicalObject $payload): string
{
    return (new CanonicalJsonV1)->encode($payload);
}
