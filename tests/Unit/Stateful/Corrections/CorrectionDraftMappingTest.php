<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLine;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionLineMode;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionAttributes;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionPositionKind;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionPayloadMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionRequestPayload;
use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionResponseMapper;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceBuyer;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;

function correctionBuyer(): InvoiceBuyer
{
    return new InvoiceBuyer(
        false,
        'Jan Kowalski',
        null,
        '00-001',
        'Warszawa',
        'Testowa 1',
        'PL',
        'jan@example.test',
        'Kowalski',
        'Jan',
    );
}

function correctionLine(string $currency = 'PLN'): CorrectionLine
{
    $before = new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Produkt testowy',
        '2.00',
        Money::fromDecimal('100.00', $currency),
        '23',
        priceNet: Money::fromDecimal('40.65', $currency),
        priceGross: Money::fromDecimal('50.00', $currency),
        totalNet: Money::fromDecimal('81.30', $currency),
    );
    $after = new CorrectionPositionAttributes(
        CorrectionPositionKind::After,
        'Produkt testowy',
        '1.00',
        Money::fromDecimal('50.00', $currency),
        '23',
        priceNet: Money::fromDecimal('40.65', $currency),
        priceGross: Money::fromDecimal('50.00', $currency),
        totalNet: Money::fromDecimal('40.65', $currency),
    );

    return new CorrectionLine(
        'Produkt testowy',
        '-1.00',
        Money::fromDecimal('-50.00', $currency),
        '23',
        $before,
        $after,
        priceNet: Money::fromDecimal('-40.65', $currency),
        priceGross: Money::fromDecimal('-50.00', $currency),
        totalNet: Money::fromDecimal('-40.65', $currency),
        mode: CorrectionLineMode::Quantity,
    );
}

it('maps a correction draft with explicit negative deltas and before-after snapshots', function (): void {
    $draft = new CorrectionDraft(
        'source-123',
        839_841,
        'Zwrot towaru',
        correctionBuyer(),
        [correctionLine()],
        '2026-08-26',
        '2026-08-25',
        'client-77',
    );
    $request = (new IssueCorrectionPayloadMapper)->map($draft);
    $body = $request->bodyWithoutCredentials();
    $invoice = $body['invoice'];
    $position = $invoice['positions'][0];

    expect($request->query())->toBe([])
        ->and($request->authenticationContract())->toBe([
            'placement' => 'json_body_top_level',
            'field' => 'api_token',
        ])
        ->and($invoice['kind'])->toBe('correction')
        ->and($invoice['invoice_id'])->toBe('source-123')
        ->and($invoice['from_invoice_id'])->toBe('source-123')
        ->and($invoice['currency'])->toBe('PLN')
        ->and($position['quantity'])->toBe('-1.00')
        ->and($position['total_price_gross'])->toBe('-50.00')
        ->and($position['correction_before_attributes']['kind'])->toBe('correction_before')
        ->and($position['correction_before_attributes']['quantity'])->toBe('2.00')
        ->and($position['correction_before_attributes']['total_price_gross'])->toBe('100.00')
        ->and($position['correction_after_attributes']['kind'])->toBe('correction_after')
        ->and($position['correction_after_attributes']['quantity'])->toBe('1.00')
        ->and($position['correction_after_attributes']['total_price_gross'])->toBe('50.00')
        ->and($body)->not->toHaveKey('api_token')
        ->and($invoice)->not->toHaveKey('api_token');
});

it('preserves negative correction values without using floating point', function (): void {
    $line = correctionLine();

    expect($line->quantity)->toBe('-1.00')
        ->and($line->totalGross->minorUnits)->toBe(-5000)
        ->and($line->totalGross->decimal())->toBe('-50.00')
        ->and($line->priceNet?->minorUnits)->toBe(-4065);

    expect(fn (): Money => Money::fromDecimal('-50.005', 'PLN'))
        ->not->toThrow(InvalidArgumentException::class);
});

it('keeps the historical positional unit contract and defaults to quantity mode', function (): void {
    $valid = correctionLine();
    $line = new CorrectionLine(
        $valid->name,
        $valid->quantity,
        $valid->totalGross,
        $valid->tax,
        $valid->before,
        $valid->after,
        'szt.',
        $valid->priceNet,
        $valid->priceGross,
        $valid->totalNet,
    );

    expect($line->unit)->toBe('szt.')
        ->and($line->mode)->toBe(CorrectionLineMode::Quantity);
});

it('rejects signed zero in before and after snapshots for value and preserved modes', function (
    CorrectionLineMode $mode,
    CorrectionPositionKind $signedSnapshot,
    string $signedZero,
): void {
    expect(function () use ($mode, $signedSnapshot, $signedZero): void {
        $before = new CorrectionPositionAttributes(
            CorrectionPositionKind::Before,
            'Pozycja',
            $signedSnapshot === CorrectionPositionKind::Before ? $signedZero : '0',
            Money::fromDecimal('10.00', 'PLN'),
            '23',
        );
        $after = new CorrectionPositionAttributes(
            CorrectionPositionKind::After,
            'Pozycja',
            $signedSnapshot === CorrectionPositionKind::After ? $signedZero : '0',
            Money::fromDecimal($mode === CorrectionLineMode::Value ? '9.00' : '10.00', 'PLN'),
            '23',
        );

        new CorrectionLine(
            'Pozycja',
            '0',
            Money::fromDecimal($mode === CorrectionLineMode::Value ? '-1.00' : '0.00', 'PLN'),
            '23',
            $before,
            $after,
            mode: $mode,
        );
    })->toThrow(
        InvalidArgumentException::class,
        'Correction position quantity must be a canonical decimal string.',
    );
})->with([
    'value before -0' => [CorrectionLineMode::Value, CorrectionPositionKind::Before, '-0'],
    'value after -0.00' => [CorrectionLineMode::Value, CorrectionPositionKind::After, '-0.00'],
    'preserved before -0.00' => [CorrectionLineMode::Preserved, CorrectionPositionKind::Before, '-0.00'],
    'preserved after -0' => [CorrectionLineMode::Preserved, CorrectionPositionKind::After, '-0'],
]);

it('rejects swapped snapshots, invalid line modes and mixed currency scales', function (): void {
    $valid = correctionLine();

    expect(fn (): CorrectionLine => new CorrectionLine(
        $valid->name,
        '-1.00',
        $valid->totalGross,
        $valid->tax,
        $valid->after,
        $valid->before,
        mode: CorrectionLineMode::Quantity,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            $valid->name,
            '0.00',
            $valid->totalGross,
            $valid->tax,
            $valid->before,
            $valid->after,
            mode: CorrectionLineMode::Quantity,
        ))->toThrow(InvalidArgumentException::class);

    expect(fn (): CorrectionDraft => new CorrectionDraft(
        'source-123',
        1,
        'Zwrot',
        correctionBuyer(),
        [$valid, correctionLine('EUR')],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn (): CorrectionLine => new CorrectionLine(
        $valid->name,
        '-0.50',
        Money::fromDecimal('-50.00', 'PLN'),
        $valid->tax,
        $valid->before,
        $valid->after,
        mode: CorrectionLineMode::Quantity,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            $valid->name,
            '-1.00',
            Money::fromDecimal('-49.99', 'PLN'),
            $valid->tax,
            $valid->before,
            $valid->after,
            mode: CorrectionLineMode::Quantity,
        ))->toThrow(InvalidArgumentException::class);
});

it('supports value-only corrections with unchanged quantity', function (): void {
    $before = new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Produkt wartościowy',
        '1.00',
        Money::fromDecimal('100.00', 'PLN'),
        '23',
    );
    $after = new CorrectionPositionAttributes(
        CorrectionPositionKind::After,
        'Produkt wartościowy',
        '1.00',
        Money::fromDecimal('75.00', 'PLN'),
        '23',
    );
    $line = new CorrectionLine(
        'Produkt wartościowy',
        '0.00',
        Money::fromDecimal('-25.00', 'PLN'),
        '23',
        $before,
        $after,
        mode: CorrectionLineMode::Value,
    );
    $payload = (new IssueCorrectionPayloadMapper)->map(new CorrectionDraft(
        'source-value',
        1,
        'Częściowy zwrot wartości',
        correctionBuyer(),
        [$line],
    ))->bodyWithoutCredentials();

    expect($line->mode)->toBe(CorrectionLineMode::Value)
        ->and($payload['invoice']['positions'][0]['quantity'])->toBe('0.00')
        ->and($payload['invoice']['positions'][0]['total_price_gross'])->toBe('-25.00')
        ->and($payload['invoice']['positions'][0]['correction_before_attributes']['quantity'])->toBe('1.00')
        ->and($payload['invoice']['positions'][0]['correction_after_attributes']['quantity'])->toBe('1.00');
});

it('preserves unchanged source lines only beside an effective correction', function (): void {
    $snapshotBefore = new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Pozycja zachowana',
        '2.00',
        Money::fromDecimal('40.00', 'PLN'),
        '23',
        totalNet: Money::fromDecimal('32.52', 'PLN'),
    );
    $snapshotAfter = new CorrectionPositionAttributes(
        CorrectionPositionKind::After,
        'Pozycja zachowana',
        '2.00',
        Money::fromDecimal('40.00', 'PLN'),
        '23',
        totalNet: Money::fromDecimal('32.52', 'PLN'),
    );
    $preserved = new CorrectionLine(
        'Pozycja zachowana',
        '0.00',
        Money::fromDecimal('0.00', 'PLN'),
        '23',
        $snapshotBefore,
        $snapshotAfter,
        totalNet: Money::fromDecimal('0.00', 'PLN'),
        mode: CorrectionLineMode::Preserved,
    );
    $draft = new CorrectionDraft(
        'source-preserved',
        1,
        'Zwrot z zachowaniem pozycji źródłowych',
        correctionBuyer(),
        [$preserved, correctionLine()],
    );
    $positions = (new IssueCorrectionPayloadMapper)->map($draft)
        ->bodyWithoutCredentials()['invoice']['positions'];

    expect($draft->positions[0]->mode)->toBe(CorrectionLineMode::Preserved)
        ->and($draft->positions[1]->mode)->toBe(CorrectionLineMode::Quantity)
        ->and($positions[0]['name'])->toBe('Pozycja zachowana')
        ->and($positions[0]['quantity'])->toBe('0.00')
        ->and($positions[1]['name'])->toBe('Produkt testowy');
});

it('rejects all-preserved drafts and mode payload mismatches', function (): void {
    $unchangedBefore = new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Pozycja',
        '1.00',
        Money::fromDecimal('10.00', 'PLN'),
        '23',
    );
    $unchangedAfter = new CorrectionPositionAttributes(
        CorrectionPositionKind::After,
        'Pozycja',
        '1.00',
        Money::fromDecimal('10.00', 'PLN'),
        '23',
    );
    $preserved = new CorrectionLine(
        'Pozycja',
        '0',
        Money::fromDecimal('0.00', 'PLN'),
        '23',
        $unchangedBefore,
        $unchangedAfter,
        mode: CorrectionLineMode::Preserved,
    );

    expect(fn (): CorrectionDraft => new CorrectionDraft(
        'source-no-op',
        1,
        'Brak zmiany',
        correctionBuyer(),
        [$preserved],
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            'Pozycja',
            '0',
            Money::fromDecimal('-1.00', 'PLN'),
            '23',
            $unchangedBefore,
            new CorrectionPositionAttributes(
                CorrectionPositionKind::After,
                'Pozycja',
                '1.00',
                Money::fromDecimal('9.00', 'PLN'),
                '23',
            ),
            mode: CorrectionLineMode::Preserved,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            'Pozycja',
            '-1',
            Money::fromDecimal('0.00', 'PLN'),
            '23',
            $unchangedBefore,
            new CorrectionPositionAttributes(
                CorrectionPositionKind::After,
                'Pozycja',
                '0',
                Money::fromDecimal('10.00', 'PLN'),
                '23',
            ),
            mode: CorrectionLineMode::Value,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            'Pozycja',
            '0',
            Money::fromDecimal('0.00', 'PLN'),
            '23',
            $unchangedBefore,
            $unchangedAfter,
            mode: CorrectionLineMode::Value,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            'Pozycja',
            '0',
            Money::fromDecimal('0.00', 'PLN'),
            '23',
            $unchangedBefore,
            $unchangedAfter,
            totalNet: Money::fromDecimal('0.01', 'PLN'),
            mode: CorrectionLineMode::Preserved,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionLine => new CorrectionLine(
            'Pozycja',
            '0',
            Money::fromDecimal('0.00', 'PLN'),
            '23',
            new CorrectionPositionAttributes(
                CorrectionPositionKind::Before,
                'Pozycja',
                '1.00',
                Money::fromDecimal('10.00', 'PLN'),
                '23',
                totalNet: Money::fromDecimal('8.13', 'PLN'),
            ),
            new CorrectionPositionAttributes(
                CorrectionPositionKind::After,
                'Pozycja',
                '1.00',
                Money::fromDecimal('10.00', 'PLN'),
                '23',
                totalNet: Money::fromDecimal('8.12', 'PLN'),
            ),
            mode: CorrectionLineMode::Preserved,
        ))->toThrow(InvalidArgumentException::class);
});

it('maps an issued correction result with string-safe identifiers and money', function (): void {
    $result = (new IssueCorrectionResponseMapper)->map([
        'id' => 987_654,
        'from_invoice_id' => 'source-123',
        'number' => 'KOR/1/08/2026',
        'status' => 'issued',
        'total_price_gross' => '-50.00',
        'currency' => 'PLN',
        'future_status_detail' => [
            'code' => 'provider-added-field',
            'retryable' => false,
        ],
    ]);

    expect($result->remoteId)->toBe('987654')
        ->and($result->sourceInvoiceId)->toBe('source-123')
        ->and($result->number)->toBe('KOR/1/08/2026')
        ->and($result->totalGross->minorUnits)->toBe(-5000)
        ->and($result->extra())->toBe([
            'future_status_detail' => [
                'code' => 'provider-added-field',
                'retryable' => false,
            ],
        ]);
});

it('rejects ambiguous float response amounts and incomplete identities', function (): void {
    $mapper = new IssueCorrectionResponseMapper;

    expect(fn () => $mapper->map([
        'id' => 'correction-1',
        'from_invoice_id' => 'source-1',
        'number' => 'KOR/1',
        'status' => 'issued',
        'total_price_gross' => -50.0,
        'currency' => 'PLN',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map([
            'id' => '',
            'from_invoice_id' => 'source-1',
            'number' => 'KOR/1',
            'status' => 'issued',
            'total_price_gross' => '-50.00',
            'currency' => 'PLN',
        ]))->toThrow(InvalidArgumentException::class);
});

it('never accepts credentials inside the request DTO', function (): void {
    $constructor = new ReflectionMethod(IssueCorrectionRequestPayload::class, '__construct');
    $request = (new IssueCorrectionPayloadMapper)->map(new CorrectionDraft(
        'source-123',
        1,
        'Zwrot',
        correctionBuyer(),
        [correctionLine()],
    ));

    expect($constructor->isPrivate())->toBeTrue()
        ->and(json_encode($request->bodyWithoutCredentials(), JSON_THROW_ON_ERROR))
        ->not->toContain('api_token')
        ->and(print_r($request, true))->not->toContain('source-123');
});

it('rejects nested credentials and mutable values from provider response extras', function (): void {
    $base = [
        'id' => 'correction-1',
        'from_invoice_id' => 'source-1',
        'number' => 'KOR/1',
        'status' => 'issued',
        'total_price_gross' => '-50.00',
        'currency' => 'PLN',
    ];
    $mapper = new IssueCorrectionResponseMapper;

    expect(fn () => $mapper->map($base + [
        'provider_meta' => ['api_token' => 'must-not-survive'],
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mapper->map($base + [
            'provider_meta' => new stdClass,
        ]))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid calendar dates and non-canonical correction identity text', function (): void {
    expect(fn (): CorrectionDraft => new CorrectionDraft(
        'source-123',
        1,
        'Zwrot',
        correctionBuyer(),
        [correctionLine()],
        '2026-99-99',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionDraft => new CorrectionDraft(
            ' source-123',
            1,
            'Zwrot',
            correctionBuyer(),
            [correctionLine()],
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): CorrectionDraft => new CorrectionDraft(
            'source-123',
            1,
            "Zwrot\nukryty",
            correctionBuyer(),
            [correctionLine()],
        ))->toThrow(InvalidArgumentException::class);
});

it('bounds correction line and snapshot text before it reaches an outbound payload', function (
    string $name,
    string $unit,
): void {
    expect(fn (): CorrectionPositionAttributes => new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        $name,
        '1',
        Money::fromDecimal('10.00', 'PLN'),
        '23',
        $unit,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty name' => ['', 'szt.'],
    'surrounding whitespace' => [' Produkt', 'szt.'],
    'control character' => ["Produkt\nukryty", 'szt.'],
    'invalid UTF-8' => ["Produkt\xFF", 'szt.'],
    'oversized name' => [str_repeat('a', 257), 'szt.'],
    'oversized unit' => ['Produkt', str_repeat('u', 33)],
]);

it('rejects unbounded correction tax representations', function (): void {
    expect(fn (): CorrectionPositionAttributes => new CorrectionPositionAttributes(
        CorrectionPositionKind::Before,
        'Produkt',
        '1',
        Money::fromDecimal('10.00', 'PLN'),
        str_repeat('9', 1000),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects unbounded buyer data at the shared command DTO boundary', function (): void {
    $invalidBuyers = [
        fn (): InvoiceBuyer => new InvoiceBuyer(
            false,
            "Jan\nKowalski",
            null,
            '00-001',
            'Warszawa',
            'Testowa 1',
            'PL',
            '',
        ),
        fn (): InvoiceBuyer => new InvoiceBuyer(
            true,
            'Firma',
            str_repeat('1', 65),
            '00-001',
            'Warszawa',
            'Testowa 1',
            'PL',
            '',
        ),
        fn (): InvoiceBuyer => new InvoiceBuyer(
            false,
            'Jan Kowalski',
            null,
            '00-001',
            'Warszawa',
            str_repeat('s', 257),
            'PL',
            '',
        ),
        fn (): InvoiceBuyer => new InvoiceBuyer(
            false,
            'Jan Kowalski',
            null,
            '00-001',
            'Warszawa',
            'Testowa 1',
            "P\u{200D}L",
            '',
        ),
    ];

    foreach ($invalidBuyers as $invalidBuyer) {
        expect($invalidBuyer)->toThrow(InvalidArgumentException::class);
    }
});
