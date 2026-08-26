<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Data\ClientListQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceListQuery;
use Cieplik206\Fakturownia\Read\Data\PaymentListQuery;
use Cieplik206\Fakturownia\Read\Data\ProductListQuery;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\GetInvoiceRequest;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\ListClientsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListInvoicesRequest;
use Cieplik206\Fakturownia\Read\Requests\ListPaymentsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProductsRequest;
use Cieplik206\Fakturownia\Read\Retry\ReadRetryPolicy;
use Cieplik206\Fakturownia\Read\Retry\RetryingReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Support\RetryAfter;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralJsonExchange;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadJitter;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;
use Cieplik206\Fakturownia\Testing\Read\RecordingReadSleeper;

final readonly class Rt3NeverRetryRequest extends JsonReadRequest
{
    public function __construct()
    {
        parent::__construct(
            ReadCapability::InvoiceGet->value,
            ReadCapability::InvoiceGet,
            '/never-retry.json',
            new QueryParameters,
            retrySafety: ReadSafety::NeverRetry,
        );
    }
}

/** @param list<array<string, mixed>> $payload */
function rt3PageExchange(ListInvoicesRequest $request, array $payload): LiteralJsonExchange
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return LiteralJsonExchange::response($request, 200, [
        'content-type' => 'application/json',
        'content-length' => (string) strlen($body),
    ], $body);
}

it('retries only explicitly read-safe requests with Retry-After and bounded full jitter', function (): void {
    $request = new GetInvoiceRequest('1');
    $successBody = '{"id":1}';
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::response($request, 429, [
            'content-type' => 'application/json',
            'retry-after' => '1',
        ], '{}'),
        LiteralJsonExchange::transportFailure($request),
        LiteralJsonExchange::response($request, 200, [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($successBody),
        ], $successBody),
    ]);
    $sleeper = new RecordingReadSleeper;
    $jitter = new LiteralReadJitter([50, 100]);
    $retrying = new RetryingReadRequestExecutor(
        $executor,
        new ReadRetryPolicy(
            maximumAttempts: 4,
            baseDelayMilliseconds: 100,
            maximumDelayMilliseconds: 1_000,
            maximumTotalDelayMilliseconds: 5_000,
        ),
        $sleeper,
        $jitter,
        new FrozenReadClock(1_788_192_000),
    );

    $response = $retrying->execute($request);

    expect($response->statusCode)->toBe(200)
        ->and($sleeper->delays())->toBe([1_000, 100])
        ->and($jitter->remainingValues())->toBe(0)
        ->and($executor->requests())->toHaveCount(3);
    $executor->assertExhausted();
});

it('never retries a descriptor that is not explicitly read-safe', function (): void {
    $request = new Rt3NeverRetryRequest;
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::transportFailure($request),
        LiteralJsonExchange::response($request, 200, ['content-type' => 'application/json'], '{}'),
    ]);
    $sleeper = new RecordingReadSleeper;
    $retrying = new RetryingReadRequestExecutor(
        $executor,
        new ReadRetryPolicy,
        $sleeper,
        new LiteralReadJitter([]),
        new FrozenReadClock(1_788_192_000),
    );

    expect(fn () => $retrying->execute($request))->toThrow(TransportFailed::class)
        ->and($sleeper->delays())->toBe([])
        ->and($executor->requests())->toHaveCount(1)
        ->and($executor->remainingExchanges())->toBe(1);
});

it('does not exceed retry delay or total delay budgets', function (): void {
    $request = new GetInvoiceRequest('1');
    $executor = new LiteralReadRequestExecutor([
        LiteralJsonExchange::response($request, 429, [
            'content-type' => 'application/json',
            'retry-after' => '2',
        ], '{}'),
        LiteralJsonExchange::response($request, 200, ['content-type' => 'application/json'], '{"id":1}'),
    ]);
    $sleeper = new RecordingReadSleeper;
    $retrying = new RetryingReadRequestExecutor(
        $executor,
        new ReadRetryPolicy(
            maximumAttempts: 4,
            baseDelayMilliseconds: 100,
            maximumDelayMilliseconds: 1_000,
            maximumTotalDelayMilliseconds: 1_500,
        ),
        $sleeper,
        new LiteralReadJitter([25]),
        new FrozenReadClock(1_788_192_000),
    );

    expect($retrying->execute($request)->statusCode)->toBe(429)
        ->and($sleeper->delays())->toBe([])
        ->and($executor->requests())->toHaveCount(1)
        ->and($executor->remainingExchanges())->toBe(1);
});

it('parses both Retry-After delta seconds and RFC 7231 dates', function (): void {
    $now = 1_788_192_000;
    $date = gmdate('D, d M Y H:i:s \\G\\M\\T', $now + 3);

    expect(RetryAfter::milliseconds(new ReadHeaders(['retry-after' => '7']), $now))->toBe(7_000)
        ->and(RetryAfter::milliseconds(new ReadHeaders(['retry-after' => " \t7\t "]), $now))->toBe(7_000)
        ->and(RetryAfter::milliseconds(new ReadHeaders(['retry-after' => $date]), $now))->toBe(3_000)
        ->and(RetryAfter::milliseconds(new ReadHeaders(['retry-after' => ['1', '9']]), $now))->toBeNull()
        ->and(RetryAfter::milliseconds(new ReadHeaders(['retry-after' => 'invalid']), $now))->toBeNull();
});

it('preserves first-seen order and stops cleanly before yielding a repeated page', function (): void {
    $query = new InvoiceListQuery(pagination: new Pagination(1, 2));
    $pageOne = new ListInvoicesRequest($query->withPage(1));
    $pageTwo = new ListInvoicesRequest($query->withPage(2));
    $executor = new LiteralReadRequestExecutor([
        rt3PageExchange($pageOne, [['id' => 1], ['id' => 2]]),
        rt3PageExchange($pageTwo, [['id' => 1], ['id' => 2]]),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoiceList]),
        new FrozenReadClock(1_788_192_000),
    );

    $invoices = iterator_to_array($client->invoices()->stream($query, 10), false);

    expect(array_map(static fn ($invoice): string => $invoice->remoteId, $invoices))->toBe(['1', '2'])
        ->and($executor->requests())->toHaveCount(2)
        ->and($executor->requests()[0]->query()->all()['order'])->toBe('id.asc')
        ->and($executor->requests()[1]->query()->all()['page'])->toBe(2);
    $executor->assertExhausted();
});

it('deduplicates overlapping pages and stops on an empty page without losing new records', function (): void {
    $query = new InvoiceListQuery(pagination: new Pagination(1, 2));
    $executor = new LiteralReadRequestExecutor([
        rt3PageExchange(new ListInvoicesRequest($query->withPage(1)), [['id' => 1], ['id' => 2]]),
        rt3PageExchange(new ListInvoicesRequest($query->withPage(2)), [['id' => 2], ['id' => 3]]),
        rt3PageExchange(new ListInvoicesRequest($query->withPage(3)), []),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoiceList]),
        new FrozenReadClock(1_788_192_000),
    );

    $invoices = iterator_to_array($client->invoices()->stream($query, 10), false);

    expect(array_map(static fn ($invoice): string => $invoice->remoteId, $invoices))->toBe(['1', '2', '3']);
    $executor->assertExhausted();
});

it('fails explicitly when the maximum page bound is reached on a full page', function (): void {
    $query = new InvoiceListQuery(pagination: new Pagination(1, 1));
    $executor = new LiteralReadRequestExecutor([
        rt3PageExchange(new ListInvoicesRequest($query), [['id' => 1]]),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoiceList]),
        new FrozenReadClock(1_788_192_000),
    );

    expect(fn () => iterator_to_array($client->invoices()->stream($query, 1), false))
        ->toThrow(PaginationLimitReached::class);
});

it('keeps client product and payment streams lazy ordered and duplicate-safe', function (): void {
    $clientQuery = new ClientListQuery(new Pagination(1, 2));
    $clientExecutor = new LiteralReadRequestExecutor([
        rt3PageExchangeFor(new ListClientsRequest($clientQuery), [['id' => 1], ['id' => 2]]),
        rt3PageExchangeFor(new ListClientsRequest($clientQuery->withPage(2)), [['id' => 2], ['id' => 3]]),
        rt3PageExchangeFor(new ListClientsRequest($clientQuery->withPage(3)), []),
    ]);
    $clientRead = new FakturowniaReadClient(
        $clientExecutor,
        new LiteralReadCapabilityGate([ReadCapability::ClientList]),
        new FrozenReadClock(1_788_192_000),
    );
    $clients = $clientRead->clients()->stream($clientQuery, 5);

    expect($clientExecutor->requests())->toBe([])
        ->and(array_map(static fn ($client): string => $client->remoteId, iterator_to_array($clients, false)))
        ->toBe(['1', '2', '3']);
    $clientExecutor->assertExhausted();

    $productQuery = new ProductListQuery(new Pagination(1, 2));
    $productExecutor = new LiteralReadRequestExecutor([
        rt3PageExchangeFor(new ListProductsRequest($productQuery), [['id' => 4], ['id' => 5]]),
        rt3PageExchangeFor(new ListProductsRequest($productQuery->withPage(2)), [['id' => 4], ['id' => 5]]),
    ]);
    $productRead = new FakturowniaReadClient(
        $productExecutor,
        new LiteralReadCapabilityGate([ReadCapability::ProductList]),
        new FrozenReadClock(1_788_192_000),
    );

    expect(array_map(
        static fn ($product): string => $product->remoteId,
        iterator_to_array($productRead->products()->stream($productQuery, 5), false),
    ))->toBe(['4', '5']);
    $productExecutor->assertExhausted();

    $paymentQuery = new PaymentListQuery(new Pagination(1, 2));
    $paymentExecutor = new LiteralReadRequestExecutor([
        rt3PageExchangeFor(new ListPaymentsRequest($paymentQuery), [['id' => 7], ['id' => 8]]),
        rt3PageExchangeFor(new ListPaymentsRequest($paymentQuery->withPage(2)), []),
    ]);
    $paymentRead = new FakturowniaReadClient(
        $paymentExecutor,
        new LiteralReadCapabilityGate([ReadCapability::PaymentList]),
        new FrozenReadClock(1_788_192_000),
    );

    expect(array_map(
        static fn ($payment): string => $payment->remoteId,
        iterator_to_array($paymentRead->payments()->stream($paymentQuery, 5), false),
    ))->toBe(['7', '8']);
    $paymentExecutor->assertExhausted();
});

/** @param list<array<string, mixed>> $payload */
function rt3PageExchangeFor(JsonReadRequest $request, array $payload): LiteralJsonExchange
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return LiteralJsonExchange::response($request, 200, [
        'content-type' => 'application/json',
        'content-length' => (string) strlen($body),
    ], $body);
}
