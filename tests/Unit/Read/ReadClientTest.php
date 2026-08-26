<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Data\ApiDate;
use Cieplik206\Fakturownia\Read\Data\ApiMonth;
use Cieplik206\Fakturownia\Read\Data\ApiTimestamp;
use Cieplik206\Fakturownia\Read\Data\ClientListQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceListQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Read\Data\KnownInvoiceKind;
use Cieplik206\Fakturownia\Read\Data\PaymentListQuery;
use Cieplik206\Fakturownia\Read\Data\ProductListQuery;
use Cieplik206\Fakturownia\Read\Exceptions\AuthenticationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\BadRequest;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\RateLimited;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteErrorEnvelope;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteServerFailed;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteValidationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\GetClientRequest;
use Cieplik206\Fakturownia\Read\Requests\GetInvoiceRequest;
use Cieplik206\Fakturownia\Read\Requests\GetPaymentRequest;
use Cieplik206\Fakturownia\Read\Requests\GetProductRequest;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\ListClientsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListInvoicesRequest;
use Cieplik206\Fakturownia\Read\Requests\ListPaymentsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProductsRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralJsonExchange;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;

/**
 * @param  array<array-key, mixed>  $payload
 * @param  array<string, string|list<string>>  $headers
 */
function rt3JsonExchange(
    JsonReadRequest $request,
    array $payload,
    int $status = 200,
    array $headers = [],
): LiteralJsonExchange {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return LiteralJsonExchange::response(
        $request,
        $status,
        $headers + [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($body),
        ],
        $body,
    );
}

/** @param list<ReadCapability> $capabilities */
function rt3ReadClient(LiteralReadRequestExecutor $executor, array $capabilities): FakturowniaReadClient
{
    return new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate($capabilities),
        new FrozenReadClock(1_788_192_000),
    );
}

it('maps invoice pages into lossless typed DTOs with open enums and canonical decimals', function (): void {
    $query = new InvoiceListQuery(
        pagination: new Pagination(page: 2, perPage: 2),
        dateFrom: '2026-08-01',
        dateTo: '2026-08-31',
        kinds: ['vat', 'correction', 'vat'],
        includePositions: true,
    );
    $request = new ListInvoicesRequest($query);
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($request, [[
            'id' => '9223372036854775808',
            'number' => 'FV/8/2026',
            'kind' => 'future_vat_kind',
            'status' => 'future_provider_status',
            'issue_date' => '2026-08-25',
            'sell_date' => '2026-08',
            'price_net' => '00089.9000',
            'price_tax' => '20.677',
            'price_gross' => '110.5770',
            'paid' => 0,
            'positions' => [[
                'id' => 501,
                'name' => 'Usługa',
                'quantity' => '2.000',
                'tax' => 23,
                'price_net' => '44.9500',
                'future_position_field' => ['safe' => true],
            ]],
            'future_invoice_field' => ['version' => 2],
        ]], headers: ['x-fakturownia-request-id' => 'req-invoice-page']),
    ]);

    $page = rt3ReadClient($executor, [ReadCapability::InvoiceList])->invoices()->list($query);
    $invoice = $page->items()[0];
    $position = $invoice->positions[0];
    $sentRequest = $executor->requests()[0];

    expect($page->number)->toBe(2)
        ->and($page->perPage)->toBe(2)
        ->and($page->providerRequestId)->toBe('req-invoice-page')
        ->and($page->isTerminal())->toBeTrue()
        ->and($invoice->remoteId)->toBe('9223372036854775808')
        ->and($invoice->kind?->raw)->toBe('future_vat_kind')
        ->and($invoice->kind?->known())->toBeNull()
        ->and($invoice->status?->raw)->toBe('future_provider_status')
        ->and($invoice->sellDate)->toBeInstanceOf(ApiMonth::class)
        ->and($invoice->priceNet?->value)->toBe('89.9')
        ->and($invoice->priceTax?->value)->toBe('20.677')
        ->and($invoice->paid?->value)->toBe('0')
        ->and($invoice->extra())->toBe(['future_invoice_field' => ['version' => 2]])
        ->and($position->remoteId)->toBe('501')
        ->and($position->quantity?->value)->toBe('2')
        ->and($position->tax)->toBe('23')
        ->and($position->extra())->toBe(['future_position_field' => ['safe' => true]])
        ->and($sentRequest->path())->toBe('/invoices.json')
        ->and($sentRequest->query()->all())->toMatchArray([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'include_positions' => 'true',
            'kinds' => ['vat', 'correction'],
            'order' => 'id.asc',
            'page' => 2,
            'per_page' => 2,
            'period' => 'more',
        ]);

    $executor->assertExhausted();
});

it('keeps clients products and payments as independent typed resources', function (): void {
    $clientRequest = new GetClientRequest('7');
    $productRequest = new GetProductRequest('9');
    $paymentQuery = new PaymentListQuery(new Pagination(1, 1), includeInvoices: true);
    $paymentRequest = new ListPaymentsRequest($paymentQuery);
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($clientRequest, [
            'id' => 7,
            'name' => 'ACME',
            'tax_no' => '1234567890',
            'company' => 'yes',
            'unexpected_client_flag' => 'kept',
        ]),
        rt3JsonExchange($productRequest, [
            'id' => 9,
            'name' => 'Abonament',
            'price_net' => 89.0,
            'tax' => 8.5,
            'created_at' => '',
        ]),
        rt3JsonExchange($paymentRequest, [[
            'id' => 77,
            'name' => 'Przelew',
            'price' => '100.5000',
            'paid' => 1,
            'paid_date' => '2026-08-25',
            'invoices' => [[
                'id' => 123,
                'kind' => KnownInvoiceKind::Vat->value,
                'status' => 'paid',
                'issue_date' => '2026-08-25',
            ]],
        ]]),
    ]);
    $read = rt3ReadClient($executor, [
        ReadCapability::ClientGet,
        ReadCapability::ProductGet,
        ReadCapability::PaymentList,
    ]);

    $client = $read->clients()->get('7');
    $product = $read->products()->get('9');
    $paymentPage = $read->payments()->list($paymentQuery);
    $payment = $paymentPage->items()[0];

    expect($client->remoteId)->toBe('7')
        ->and($client->company)->toBeTrue()
        ->and($client->extra())->toBe(['unexpected_client_flag' => 'kept'])
        ->and($product->remoteId)->toBe('9')
        ->and($product->priceNet?->value)->toBe('89')
        ->and($product->tax)->toBe('8.5')
        ->and($product->createdAt)->toBeNull()
        ->and($payment->remoteId)->toBe('77')
        ->and($payment->price?->value)->toBe('100.5')
        ->and($payment->paid)->toBeTrue()
        ->and($payment->paidAt)->toBeInstanceOf(ApiDate::class)
        ->and($payment->invoices[0]->remoteId)->toBe('123')
        ->and($executor->requests()[2]->query()->all())->toMatchArray([
            'include' => 'invoices',
            'page' => 1,
            'per_page' => 1,
        ]);

    $executor->assertExhausted();
});

it('implements successful invoice get and independent client and product list contracts', function (): void {
    $invoiceRequest = new GetInvoiceRequest('12');
    $clientQuery = new ClientListQuery(new Pagination(3, 2), taxNumber: '1234567890');
    $clientRequest = new ListClientsRequest($clientQuery);
    $productQuery = new ProductListQuery(new Pagination(4, 2));
    $productRequest = new ListProductsRequest($productQuery);
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($invoiceRequest, [
            'id' => 12,
            'kind' => 'vat',
            'status' => 'paid',
            'sell_date' => '2026-08-25',
        ]),
        rt3JsonExchange($clientRequest, [
            ['id' => 21, 'name' => 'Pierwszy'],
            ['id' => 22, 'name' => 'Drugi'],
        ]),
        rt3JsonExchange($productRequest, [
            ['id' => 31, 'name' => 'A'],
        ]),
    ]);
    $client = rt3ReadClient($executor, [
        ReadCapability::InvoiceGet,
        ReadCapability::ClientList,
        ReadCapability::ProductList,
    ]);

    $invoice = $client->invoices()->get('12');
    $clients = $client->clients()->list($clientQuery);
    $products = $client->products()->list($productQuery);

    expect($invoice->remoteId)->toBe('12')
        ->and($invoice->kind?->known())->toBe(KnownInvoiceKind::Vat)
        ->and($invoice->sellDate)->toBeInstanceOf(ApiDate::class)
        ->and(array_map(static fn ($item): string => $item->remoteId, $clients->items()))->toBe(['21', '22'])
        ->and($clients->isTerminal())->toBeFalse()
        ->and($products->items()[0]->remoteId)->toBe('31')
        ->and($products->isTerminal())->toBeTrue()
        ->and($executor->requests()[1]->path())->toBe('/clients.json')
        ->and($executor->requests()[1]->query()->all())->toMatchArray([
            'page' => 3,
            'per_page' => 2,
            'tax_no' => '1234567890',
        ])
        ->and($executor->requests()[2]->path())->toBe('/products.json')
        ->and($executor->requests()[2]->query()->all())->toBe(['page' => 4, 'per_page' => 2]);

    $executor->assertExhausted();
});

it('keeps payment detail hard fail-closed while retaining only the reviewed singular descriptor', function (): void {
    $blockedExecutor = new LiteralReadRequestExecutor([]);
    $blockedClient = rt3ReadClient($blockedExecutor, [ReadCapability::PaymentList, ReadCapability::PaymentGet]);

    expect(fn () => $blockedClient->payments()->get('77'))
        ->toThrow(UnsupportedCapability::class)
        ->and($blockedExecutor->requests())->toBe([])
        ->and((new GetPaymentRequest('77'))->path())->toBe('/banking/payment/77.json');
});

it('detects HTTP 200 error envelopes without exposing the remote message', function (): void {
    $request = new GetInvoiceRequest('1');
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($request, [
            'code' => 'validation.failed',
            'message' => 'secret tenant diagnostics',
        ]),
    ]);

    try {
        rt3ReadClient($executor, [ReadCapability::InvoiceGet])->invoices()->get('1');
    } catch (RemoteErrorEnvelope $exception) {
        expect($exception->remoteCode)->toBe('validation.failed')
            ->and($exception->getMessage())->not->toContain('secret tenant diagnostics');

        return;
    }

    throw new RuntimeException('The HTTP 200 error envelope was unexpectedly accepted.');
});

it('detects HTTP 200 error envelopes returned by list endpoints', function (): void {
    $query = new InvoiceListQuery;
    $request = new ListInvoicesRequest($query);
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($request, ['code' => 'error', 'message' => 'private diagnostics']),
    ]);

    expect(fn () => rt3ReadClient($executor, [ReadCapability::InvoiceList])->invoices()->list($query))
        ->toThrow(RemoteErrorEnvelope::class);
});

/** @param class-string<Throwable> $expectedException */
it('classifies unsuccessful response statuses', function (int $status, string $expectedException): void {
    $request = new GetInvoiceRequest('1');
    $body = '{}';
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::response($request, $status, [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($body),
            'retry-after' => '2',
        ], $body),
    ]);

    expect(fn () => rt3ReadClient($executor, [ReadCapability::InvoiceGet])->invoices()->get('1'))
        ->toThrow($expectedException);
})->with([
    'bad request' => [400, BadRequest::class],
    'authentication' => [401, AuthenticationFailed::class],
    'not found' => [404, ResourceNotFound::class],
    'validation' => [422, RemoteValidationFailed::class],
    'rate limit' => [429, RateLimited::class],
    'remote server' => [503, RemoteServerFailed::class],
]);

it('rejects malformed JSON wrong response media types and payload references', function (): void {
    $malformedRequest = new GetInvoiceRequest('1');
    $mimeRequest = new GetInvoiceRequest('2');
    $malformedBody = '{';
    $mimeBody = '{"id":2}';
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::response($malformedRequest, 200, [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($malformedBody),
        ], $malformedBody),
        LiteralJsonExchange::response($mimeRequest, 200, [
            'content-type' => 'text/html',
            'content-length' => (string) strlen($mimeBody),
        ], $mimeBody),
    ]);
    $client = rt3ReadClient($executor, [ReadCapability::InvoiceGet]);

    expect(fn () => $client->invoices()->get('1'))->toThrow(ProtocolViolation::class)
        ->and(fn () => $client->invoices()->get('2'))->toThrow(ProtocolViolation::class);

    $external = 'before';
    $payload = ['id' => 1, 'unknown' => &$external];

    expect(fn () => InvoiceResponseData::fromPayload($payload, 'invoice.read.get'))
        ->toThrow(ProtocolViolation::class);
});

it('rejects lossy provider floats in generic invoice DTO mapping', function (array $payload): void {
    expect(fn () => InvoiceResponseData::fromPayload($payload, 'invoice.read.list'))
        ->toThrow(ProtocolViolation::class);
})->with([
    'invoice money' => [['id' => 1, 'price_gross' => 123.45]],
    'position quantity' => [['id' => 1, 'positions' => [['quantity' => 2.0]]]],
    'position tax' => [['id' => 1, 'positions' => [['tax' => 23.0]]]],
]);

it('keeps credentials out of descriptors and literal exchanges exact', function (): void {
    expect(fn () => new QueryParameters(['api_token' => 'secret']))
        ->toThrow(InvalidArgumentException::class);

    $expected = new GetInvoiceRequest('1');
    $executor = new LiteralReadRequestExecutor([
        rt3JsonExchange($expected, ['id' => 1]),
    ]);
    $client = rt3ReadClient($executor, [ReadCapability::InvoiceGet]);

    expect(fn () => $client->invoices()->get('2'))->toThrow(LogicException::class)
        ->and($executor->remainingExchanges())->toBe(1)
        ->and($executor->requests())->toBe([]);
});

it('redacts and seals credential-bearing read client and resource wrappers', function (): void {
    $executor = new LiteralReadRequestExecutor([]);
    $client = rt3ReadClient($executor, []);
    $encoded = json_encode($client, JSON_THROW_ON_ERROR);

    expect($encoded)->toContain('[REDACTED]')
        ->not->toContain(LiteralReadRequestExecutor::class)
        ->and($client->invoices()->__debugInfo())->toBe([
            'transport' => 'sealed-read-executor',
            'credentials' => '[REDACTED]',
        ])
        ->and(fn () => clone $client)->toThrow(LogicException::class)
        ->and(fn () => serialize($client))->toThrow(LogicException::class)
        ->and((new TransportFailed('invoice.read.get'))->getPrevious())->toBeNull();
});

it('validates independent list query boundaries', function (): void {
    expect(fn () => new Pagination(perPage: 101))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new InvoiceListQuery(dateFrom: '2026-02-30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ApiTimestamp('2026-02-30T25:61:61+15:30'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReadHeaders([
            'Content-Type' => 'application/json',
            'content-type' => 'text/html',
        ]))->toThrow(InvalidArgumentException::class)
        ->and((new ReadHeaders([
            'set-cookie' => 'session=secret',
            'server-timing' => 'private-description',
            'x-request-id' => 'safe-request-id',
        ]))->all())->toBe(['x-request-id' => ['safe-request-id']])
        ->and((new ClientListQuery(new Pagination(1, 100)))->toQuery()['per_page'])->toBe(100)
        ->and((new ProductListQuery(new Pagination(1, 100)))->toQuery()['per_page'])->toBe(100);
});

it('maps oversized remote pages to a typed protocol violation for every list resource', function (): void {
    $invoiceQuery = new InvoiceListQuery(new Pagination(1, 1));
    $invoiceRequest = new ListInvoicesRequest($invoiceQuery);
    $invoiceExecutor = new LiteralReadRequestExecutor([
        rt3JsonExchange($invoiceRequest, [['id' => 1], ['id' => 2]]),
    ]);

    expect(fn () => rt3ReadClient($invoiceExecutor, [ReadCapability::InvoiceList])->invoices()->list($invoiceQuery))
        ->toThrow(ProtocolViolation::class);

    $clientQuery = new ClientListQuery(new Pagination(1, 1));
    $clientRequest = new ListClientsRequest($clientQuery);
    $clientExecutor = new LiteralReadRequestExecutor([
        rt3JsonExchange($clientRequest, [['id' => 1], ['id' => 2]]),
    ]);

    expect(fn () => rt3ReadClient($clientExecutor, [ReadCapability::ClientList])->clients()->list($clientQuery))
        ->toThrow(ProtocolViolation::class);

    $productQuery = new ProductListQuery(new Pagination(1, 1));
    $productRequest = new ListProductsRequest($productQuery);
    $productExecutor = new LiteralReadRequestExecutor([
        rt3JsonExchange($productRequest, [['id' => 1], ['id' => 2]]),
    ]);

    expect(fn () => rt3ReadClient($productExecutor, [ReadCapability::ProductList])->products()->list($productQuery))
        ->toThrow(ProtocolViolation::class);

    $paymentQuery = new PaymentListQuery(new Pagination(1, 1));
    $paymentRequest = new ListPaymentsRequest($paymentQuery);
    $paymentExecutor = new LiteralReadRequestExecutor([
        rt3JsonExchange($paymentRequest, [['id' => 1], ['id' => 2]]),
    ]);

    expect(fn () => rt3ReadClient($paymentExecutor, [ReadCapability::PaymentList])->payments()->list($paymentQuery))
        ->toThrow(ProtocolViolation::class);
});
