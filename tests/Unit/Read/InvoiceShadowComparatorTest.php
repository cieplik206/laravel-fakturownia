<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Shadow\InvoiceShadowComparator;
use Cieplik206\Fakturownia\Read\Shadow\ShadowDifferenceKind;

it('normalizes exact scalar money open enums and position order', function (): void {
    $legacy = invoiceShadowData([
        'id' => 42,
        'kind' => 'future-kind',
        'status' => 'future-status',
        'price_gross' => '123.4500',
        'paid' => '0.00',
        'positions' => [
            invoiceShadowPosition(2, 'Second', '20.00'),
            invoiceShadowPosition(1, 'First', '10.000'),
        ],
    ]);
    $sdk = invoiceShadowData([
        'id' => '42',
        'kind' => 'future-kind',
        'status' => 'future-status',
        'price_gross' => '123.45',
        'paid' => 0,
        'positions' => [
            invoiceShadowPosition('1', 'First', 10),
            invoiceShadowPosition('2', 'Second', '20.0'),
        ],
    ]);

    $result = (new InvoiceShadowComparator)->compare($legacy, $sdk);

    expect($result->matches())->toBeTrue()
        ->and($result->differences)->toBe([])
        ->and($result->jsonSerialize())->toMatchArray([
            'matches' => true,
            'difference_count' => 0,
        ]);
});

it('reports only structured paths and never compared values', function (): void {
    $legacy = invoiceShadowData([
        'id' => '42',
        'status' => 'legacy-secret-status',
        'price_gross' => '123.45',
        'buyer_email' => 'legacy-secret@example.test',
        'positions' => [invoiceShadowPosition('1', 'Sensitive legacy name', '10')],
    ]);
    $sdk = invoiceShadowData([
        'id' => '42',
        'status' => 'sdk-secret-status',
        'price_gross' => '999.99',
        'buyer_email' => 'sdk-secret@example.test',
        'positions' => [
            invoiceShadowPosition('1', 'Sensitive SDK name', '10'),
            invoiceShadowPosition('2', 'Another sensitive name', '5'),
        ],
    ]);

    $result = (new InvoiceShadowComparator)->compare($legacy, $sdk);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    expect($result->matches())->toBeFalse()
        ->and(array_map(
            static fn ($difference): string => $difference->path,
            $result->differences,
        ))->toBe([
            'status',
            'price_gross',
            'buyer_email',
            'positions.count',
            'positions.set',
        ])
        ->and($result->differences[0]->kind)->toBe(ShadowDifferenceKind::ValueMismatch)
        ->and($result->differences[3]->kind)->toBe(ShadowDifferenceKind::PositionCountMismatch)
        ->and($result->differences[4]->kind)->toBe(ShadowDifferenceKind::PositionSetMismatch)
        ->and($encoded)->not->toContain('legacy-secret')
        ->and($encoded)->not->toContain('sdk-secret')
        ->and($encoded)->not->toContain('Sensitive')
        ->and($encoded)->not->toContain('999.99');
});

it('detects a provider department mismatch without exposing either identifier', function (): void {
    $legacy = invoiceShadowData([
        'department_id' => '111111',
    ]);
    $sdk = invoiceShadowData([
        'department_id' => '999999',
    ]);

    $result = (new InvoiceShadowComparator)->compare($legacy, $sdk);
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    expect($result->matches())->toBeFalse()
        ->and($result->differences)->toHaveCount(1)
        ->and($result->differences[0]->path)->toBe('department_id')
        ->and($encoded)->not->toContain('111111')
        ->and($encoded)->not->toContain('999999');
});

/** @param array<string, mixed> $overrides */
function invoiceShadowData(array $overrides): InvoiceResponseData
{
    return InvoiceResponseData::fromPayload(array_replace([
        'id' => '42',
        'number' => 'FV/42',
        'kind' => 'vat',
        'status' => 'issued',
        'issue_date' => '2026-08-26',
        'sell_date' => '2026-08',
        'payment_to' => '2026-09-02',
        'payment_type' => 'transfer',
        'price_net' => '100',
        'price_tax' => '23',
        'price_gross' => '123',
        'paid' => '0',
        'currency' => 'PLN',
        'buyer_name' => 'Buyer',
        'buyer_email' => 'buyer@example.test',
        'income' => true,
        'cancelled' => false,
        'created_at' => '2026-08-26T10:00:00+02:00',
        'updated_at' => '2026-08-26T10:05:00+02:00',
        'positions' => [],
    ], $overrides), 'invoice.shadow');
}

/** @return array<string, mixed> */
function invoiceShadowPosition(int|string $id, string $name, int|string $gross): array
{
    return [
        'id' => $id,
        'invoice_id' => '42',
        'product_id' => $id,
        'name' => $name,
        'quantity' => '1.00',
        'quantity_unit' => 'szt.',
        'tax' => '23',
        'price_net' => '8.13',
        'price_gross' => $gross,
        'total_price_net' => '8.13',
        'total_price_gross' => $gross,
    ];
}
