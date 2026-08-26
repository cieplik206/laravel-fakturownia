<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Data\ExactOidInvoiceQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\FindInvoicesByExactOidRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralJsonExchange;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;

it('scans every exact OID page and exposes the provider department identifier', function (): void {
    $query = new ExactOidInvoiceQuery(
        'ORDER-2026-42',
        'vat',
        true,
        '2026-08-25',
        new Pagination(1, 2),
    );
    $executor = new LiteralReadRequestExecutor([
        rt3ExactOidExchange($query->withPage(1), [
            rt3ExactOidInvoice('101', '17'),
            rt3ExactOidInvoice('102', '17'),
        ]),
        rt3ExactOidExchange($query->withPage(2), [
            rt3ExactOidInvoice('103', '17'),
        ], 'request-last-page'),
    ]);
    $client = rt3ExactOidClient($executor);

    $invoices = iterator_to_array($client->invoices()->streamByExactOid($query, 3), false);
    $requests = $executor->requests();

    expect(array_map(static fn (InvoiceResponseData $invoice): string => $invoice->remoteId, $invoices))
        ->toBe(['101', '102', '103'])
        ->and($invoices[0]->departmentId)->toBe('17')
        ->and($invoices[0]->sourceOid)->toBe('ORDER-2026-42')
        ->and($requests)->toHaveCount(2)
        ->and($requests[0])->toBeInstanceOf(FindInvoicesByExactOidRequest::class)
        ->and($requests[0]->query()->all())->toBe([
            'date_from' => '2026-08-25',
            'date_to' => '2026-08-25',
            'include_positions' => 'true',
            'income' => 'yes',
            'kind' => 'vat',
            'oid' => 'ORDER-2026-42',
            'order' => 'id.asc',
            'page' => 1,
            'per_page' => 2,
            'period' => 'more',
            'search_date_type' => 'issue_date',
        ])
        ->and($requests[1]->query()->all()['page'])->toBe(2);

    $executor->assertExhausted();
});

it('fails closed when an exact OID scan does not start at page one', function (): void {
    $query = new ExactOidInvoiceQuery(
        'ORDER-42',
        'vat',
        true,
        '2026-08-25',
        new Pagination(2, 100),
    );
    $executor = new LiteralReadRequestExecutor([]);

    expect(fn () => iterator_to_array(
        rt3ExactOidClient($executor)->invoices()->streamByExactOid($query),
        false,
    ))->toThrow(ProtocolViolation::class)
        ->and($executor->requests())->toBe([]);
});

it('fails closed instead of treating a repeated full exact OID page as exhaustion', function (): void {
    $query = new ExactOidInvoiceQuery(
        'ORDER-42',
        'vat',
        false,
        '2026-08-25',
        new Pagination(1, 2),
    );
    $repeated = [
        rt3ExactOidInvoice('101', '17', false),
        rt3ExactOidInvoice('102', '17', false),
    ];
    $executor = new LiteralReadRequestExecutor([
        rt3ExactOidExchange($query->withPage(1), $repeated),
        rt3ExactOidExchange($query->withPage(2), $repeated),
    ]);

    expect(fn () => iterator_to_array(
        rt3ExactOidClient($executor)->invoices()->streamByExactOid($query, 3),
        false,
    ))->toThrow(ProtocolViolation::class);

    $executor->assertExhausted();
});

it('rejects unbounded or ambiguous exact OID filters before dispatch', function (): void {
    expect(fn () => new ExactOidInvoiceQuery('', 'vat', true, '2026-08-25'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExactOidInvoiceQuery("ORDER\0SECRET", 'vat', true, '2026-08-25'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExactOidInvoiceQuery('ORDER-42', '../vat', true, '2026-08-25'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExactOidInvoiceQuery('ORDER-42', 'vat', true, '2026-02-30'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects provider floats before an exact OID response becomes a reconciliation DTO', function (string $field): void {
    $query = new ExactOidInvoiceQuery('ORDER-2026-42', 'vat', true, '2026-08-25');
    $invoice = rt3ExactOidInvoice('101', '17');
    $invoice['price_gross'] = '123.45';
    $invoice['positions'] = [[
        'id' => '501',
        'quantity' => '2',
        'tax' => '23',
        'total_price_gross' => '123.45',
    ]];

    if ($field === 'price_gross') {
        $invoice['price_gross'] = 123.45;
    } else {
        $invoice['positions'][0][$field] = match ($field) {
            'quantity' => 2.0,
            'tax' => 23.0,
            'total_price_gross' => 123.45,
            default => throw new InvalidArgumentException("Unsupported provider float field: {$field}."),
        };
    }

    $executor = new LiteralReadRequestExecutor([
        rt3ExactOidExchange($query, [$invoice]),
    ]);

    expect(fn () => iterator_to_array(
        rt3ExactOidClient($executor)->invoices()->streamByExactOid($query),
        false,
    ))->toThrow(ProtocolViolation::class);

    $executor->assertExhausted();
})->with([
    'invoice gross' => 'price_gross',
    'position quantity' => 'quantity',
    'position tax' => 'tax',
    'position gross' => 'total_price_gross',
]);

/** @param list<array<string, mixed>> $payload */
function rt3ExactOidExchange(
    ExactOidInvoiceQuery $query,
    array $payload,
    ?string $providerRequestId = null,
): LiteralJsonExchange {
    $request = new FindInvoicesByExactOidRequest($query);
    $body = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
    );
    $headers = [
        'content-type' => 'application/json',
        'content-length' => (string) strlen($body),
    ];

    if ($providerRequestId !== null) {
        $headers['x-request-id'] = $providerRequestId;
    }

    return LiteralJsonExchange::response($request, 200, $headers, $body);
}

/** @return array<string, mixed> */
function rt3ExactOidInvoice(string $id, string $departmentId, bool $income = true): array
{
    return [
        'id' => $id,
        'department_id' => $departmentId,
        'oid' => 'ORDER-2026-42',
        'kind' => 'vat',
        'income' => $income,
        'issue_date' => '2026-08-25',
        'created_at' => '2026-08-25T12:00:00+02:00',
        'positions' => [],
    ];
}

function rt3ExactOidClient(LiteralReadRequestExecutor $executor): FakturowniaReadClient
{
    return new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoiceList]),
        new FrozenReadClock(1_788_192_000),
    );
}
