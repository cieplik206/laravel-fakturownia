<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ReadTransport\PinnedReadCapabilityGate;
use Cieplik206\Fakturownia\Read\Data\KnownInvoiceKind;
use Cieplik206\Fakturownia\Read\Data\ProformaListQuery;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\GetProformaRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProformasRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralJsonExchange;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;

it('lists only income proformas through the exact invoice read scope', function (): void {
    $fixture = s82ProformaReadFixture();
    $query = new ProformaListQuery(
        pagination: new Pagination(1, 2),
        dateFrom: '2026-08-01',
        dateTo: '2026-08-31',
        includePositions: true,
    );
    $request = new ListProformasRequest($query);
    $executor = new LiteralReadRequestExecutor([
        s82ProformaExchange($request, [$fixture['response']]),
    ]);
    $page = s82ProformaClient($executor, [ReadCapability::InvoiceList])
        ->proformas()
        ->list($query);
    $proforma = $page->items()[0];
    $snapshot = $proforma->snapshot;
    $sent = $executor->requests()[0];

    expect($fixture['evidence_status'])->toBe('synthetic_deferred_no_live_evidence')
        ->and($snapshot->remoteId)->toBe('710001')
        ->and($snapshot->kind?->known())->toBe(KnownInvoiceKind::Proforma)
        ->and($snapshot->status?->raw)->toBe('future_proforma_state')
        ->and($snapshot->status?->known())->toBeNull()
        ->and($snapshot->priceGross?->value)->toBe('100')
        ->and($snapshot->paid?->value)->toBe('0')
        ->and($snapshot->extra())->toBe(['future_proforma_field' => ['version' => 2]])
        ->and($snapshot->positions[0]->extra())->toBe(['future_position_field' => 'preserved'])
        ->and($sent->path())->toBe($fixture['list_request']['path'])
        ->and($sent->query()->all())->toBe($fixture['list_request']['query'])
        ->and($sent->query()->all())->not->toHaveKeys(['api_token', 'authorization', 'token'])
        ->and(print_r($proforma, true))
        ->not->toContain('buyer@example.test')
        ->not->toContain('PL0000000000')
        ->and(fn (): string => serialize($proforma))->toThrow(LogicException::class);

    $executor->assertExhausted();
});

it('gets a proforma only when the remote resource confirms the exact kind', function (): void {
    $fixture = s82ProformaReadFixture();
    $request = new GetProformaRequest('710001');
    $executor = new LiteralReadRequestExecutor([
        s82ProformaExchange($request, $fixture['response']),
    ]);
    $proforma = s82ProformaClient($executor, [ReadCapability::InvoiceGet])
        ->proformas()
        ->get('710001');
    $sent = $executor->requests()[0];

    expect($proforma->snapshot->remoteId)->toBe('710001')
        ->and($sent->path())->toBe($fixture['get_request']['path'])
        ->and($sent->query()->all())->toBe($fixture['get_request']['query']);

    $executor->assertExhausted();
});

it('rejects a missing or different remote invoice kind instead of reinterpreting it', function (?string $kind): void {
    $fixture = s82ProformaReadFixture();
    $payload = $fixture['response'];

    if ($kind === null) {
        unset($payload['kind']);
    } else {
        $payload['kind'] = $kind;
    }

    $request = new GetProformaRequest('710001');
    $executor = new LiteralReadRequestExecutor([
        s82ProformaExchange($request, $payload),
    ]);

    expect(fn () => s82ProformaClient($executor, [ReadCapability::InvoiceGet])
        ->proformas()
        ->get('710001'))
        ->toThrow(ProtocolViolation::class, 'exact proforma kind field');

    $executor->assertExhausted();
})->with([
    'missing' => null,
    'VAT legacy fake' => 'vat',
    'correction' => 'correction',
]);

it('rejects float money provenance in a proforma response', function (): void {
    $fixture = s82ProformaReadFixture();
    $payload = $fixture['response'];
    $payload['price_gross'] = 100.0;
    $request = new GetProformaRequest('710001');
    $executor = new LiteralReadRequestExecutor([
        s82ProformaExchange($request, $payload),
    ]);

    expect(fn () => s82ProformaClient($executor, [ReadCapability::InvoiceGet])
        ->proformas()
        ->get('710001'))
        ->toThrow(ProtocolViolation::class, 'exact decimal provenance');

    $executor->assertExhausted();
});

it('keeps the production proforma read capability closed without pinned live evidence', function (): void {
    $executor = new LiteralReadRequestExecutor([]);
    $client = new FakturowniaReadClient(
        $executor,
        new PinnedReadCapabilityGate,
        new FrozenReadClock(1_788_192_000),
    );

    expect(fn () => $client->proformas()->list())
        ->toThrow(UnsupportedCapability::class)
        ->and($executor->requests())->toBe([]);
});

/**
 * @param  array<string, mixed>|list<array<string, mixed>>  $payload
 */
function s82ProformaExchange(
    GetProformaRequest|ListProformasRequest $request,
    array $payload,
): LiteralJsonExchange {
    $body = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
    );

    return LiteralJsonExchange::response($request, 200, [
        'content-type' => 'application/json',
        'content-length' => (string) strlen($body),
    ], $body);
}

/** @param list<ReadCapability> $capabilities */
function s82ProformaClient(
    LiteralReadRequestExecutor $executor,
    array $capabilities,
): FakturowniaReadClient {
    return new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate($capabilities),
        new FrozenReadClock(1_788_192_000),
    );
}

/**
 * @return array{
 *     contract: string,
 *     version: int,
 *     evidence_status: string,
 *     list_request: array{method: string, path: string, query: array<string, mixed>},
 *     get_request: array{method: string, path: string, query: array<string, mixed>},
 *     response: array<string, mixed>
 * }
 */
function s82ProformaReadFixture(): array
{
    $contents = file_get_contents(
        dirname(__DIR__, 2).'/Fixtures/Read/Proformas/proforma-read-contract.json',
    );
    $decoded = is_string($contents)
        ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
        : null;

    if (! is_array($decoded)) {
        throw new RuntimeException('The S8.2 proforma read fixture is invalid.');
    }

    /** @var array{
     *     contract: string,
     *     version: int,
     *     evidence_status: string,
     *     list_request: array{method: string, path: string, query: array<string, mixed>},
     *     get_request: array{method: string, path: string, query: array<string, mixed>},
     *     response: array<string, mixed>
     * } $decoded
     */
    return $decoded;
}
