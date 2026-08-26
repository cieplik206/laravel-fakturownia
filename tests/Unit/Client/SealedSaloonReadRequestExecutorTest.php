<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\ReadTransport\SealedSaloonReadRequestExecutor;
use Cieplik206\Fakturownia\Client\ReadTransport\Testing\InMemorySaloonExchange;
use Cieplik206\Fakturownia\Client\ReadTransport\Testing\InMemorySaloonReadRequestExecutor;
use Cieplik206\Fakturownia\Client\ReadTransport\Testing\InMemorySaloonSender;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Read\Data\ExactOidInvoiceQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceListQuery;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoicePdfRequest;
use Cieplik206\Fakturownia\Read\Requests\FindInvoicesByExactOidRequest;
use Cieplik206\Fakturownia\Read\Requests\GetInvoiceRequest;
use Cieplik206\Fakturownia\Read\Requests\GetPaymentRequest;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\ListInvoicesRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use Saloon\Config;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;

afterEach(function (): void {
    MockClient::destroyGlobal();
    Config::clearGlobalMiddleware();
    Config::setSenderResolver(null);
    Config::$defaultSender = GuzzleSender::class;
    Config::$defaultTlsMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    Config::$defaultConnectionTimeout = 10;
    Config::$defaultRequestTimeout = 30;
});

it('maps an exact JSON descriptor to one credentialed GET without a body', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(
            200,
            ['Content-Type' => ['application/json'], 'X-Request-Id' => ['request-42']],
            '{"id":42}',
        ),
    ]);
    $request = new GetInvoiceRequest('42');

    $response = $executor->execute($request);
    $sent = $sender->requests()[0];
    parse_str($sent->getUri()->getQuery(), $query);

    expect($response->statusCode)->toBe(200)
        ->and($response->body($request))->toBe('{"id":42}')
        ->and($response->headers->providerRequestId())->toBe('request-42')
        ->and($sender->requests())->toHaveCount(1)
        ->and($sent->getMethod())->toBe('GET')
        ->and($sent->getUri()->getScheme())->toBe('https')
        ->and($sent->getUri()->getHost())->toBe(InMemorySaloonReadRequestExecutor::OriginHost)
        ->and($sent->getUri()->getPath())->toBe('/invoices/42.json')
        ->and($query)->toBe(['api_token' => 'in-memory-contract-token'])
        ->and((string) $sent->getBody())->toBe('')
        ->and($sent->hasHeader('Authorization'))->toBeFalse()
        ->and($sent->hasHeader('Cookie'))->toBeFalse();
});

it('preserves typed list query data while adding the token only at dispatch', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(200, ['Content-Type' => ['application/json']], '[]'),
    ]);
    $request = new ListInvoicesRequest(new InvoiceListQuery(
        number: 'FV/42',
        kinds: ['vat', 'proforma'],
        includePositions: true,
    ));

    $executor->execute($request);
    parse_str($sender->requests()[0]->getUri()->getQuery(), $query);

    expect($query['api_token'] ?? null)->toBe('in-memory-contract-token')
        ->and($query['number'] ?? null)->toBe('FV/42')
        ->and($query['kinds'] ?? null)->toBe(['vat', 'proforma'])
        ->and($query['include_positions'] ?? null)->toBe('true')
        ->and($query['per_page'] ?? null)->toBe('100');
});

it('dispatches only the sealed exact OID invoice-list tuple', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(200, ['Content-Type' => ['application/json']], '[]'),
    ]);
    $request = new FindInvoicesByExactOidRequest(new ExactOidInvoiceQuery(
        'ORDER-42',
        'vat',
        true,
        '2026-08-25',
    ));

    $executor->execute($request);
    parse_str($sender->requests()[0]->getUri()->getQuery(), $query);

    expect($sender->requests()[0]->getUri()->getPath())->toBe('/invoices.json')
        ->and($query)->toBe([
            'date_from' => '2026-08-25',
            'date_to' => '2026-08-25',
            'include_positions' => 'true',
            'income' => 'yes',
            'kind' => 'vat',
            'oid' => 'ORDER-42',
            'order' => 'id.asc',
            'page' => '1',
            'per_page' => '100',
            'period' => 'more',
            'search_date_type' => 'issue_date',
            'api_token' => 'in-memory-contract-token',
        ]);
});

it('follows an allowlisted cross-host artifact redirect without forwarding credentials', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(302, [
            'Location' => ['https://'.InMemorySaloonReadRequestExecutor::RedirectHost.'/document.pdf?signature=abc%2F123'],
        ]),
        InMemorySaloonExchange::response(200, [
            'Content-Type' => ['application/pdf'],
            'Content-Length' => ['14'],
        ], "%PDF-1.7\n%%EOF"),
    ]);

    $response = $executor->stream(new DownloadInvoicePdfRequest('7'));
    $requests = $sender->requests();
    parse_str($requests[0]->getUri()->getQuery(), $initialQuery);
    parse_str($requests[1]->getUri()->getQuery(), $redirectQuery);

    expect($response->redirectCount)->toBe(1)
        ->and($response->crossHostRedirected)->toBeTrue()
        ->and($response->credentialsStrippedOnRedirect)->toBeTrue()
        ->and($response->body->read(14))->toBe("%PDF-1.7\n%%EOF")
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->getUri()->getHost())->toBe(InMemorySaloonReadRequestExecutor::OriginHost)
        ->and($initialQuery['api_token'] ?? null)->toBe('in-memory-contract-token')
        ->and($requests[1]->getUri()->getHost())->toBe(InMemorySaloonReadRequestExecutor::RedirectHost)
        ->and($requests[1]->getUri()->getQuery())->toBe('signature=abc%2F123')
        ->and($redirectQuery)->toBe(['signature' => 'abc/123'])
        ->and($requests[1]->hasHeader('Authorization'))->toBeFalse()
        ->and($requests[1]->hasHeader('Cookie'))->toBeFalse()
        ->and($requests[1]->getUri()->getQuery())->not->toContain('api_token');

    $response->body->close();
});

it('never reattaches credentials after a cross-host redirect bounce', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(302, [
            'Location' => ['https://'.InMemorySaloonReadRequestExecutor::RedirectHost.'/first.pdf?signature=one'],
        ]),
        InMemorySaloonExchange::response(302, [
            'Location' => ['https://'.InMemorySaloonReadRequestExecutor::OriginHost.'/final.pdf?signature=two'],
        ]),
        InMemorySaloonExchange::response(200, [
            'Content-Type' => ['application/pdf'],
        ], "%PDF-1.7\n%%EOF"),
    ]);

    $response = $executor->stream(new DownloadInvoicePdfRequest('8'));
    $requests = $sender->requests();

    expect($requests)->toHaveCount(3)
        ->and($requests[0]->getUri()->getQuery())->toContain('api_token=in-memory-contract-token')
        ->and($requests[1]->getUri()->getQuery())->not->toContain('api_token')
        ->and($requests[2]->getUri()->getQuery())->not->toContain('api_token')
        ->and($response->redirectCount)->toBe(2)
        ->and($response->crossHostRedirected)->toBeTrue()
        ->and($response->credentialsStrippedOnRedirect)->toBeTrue();

    $response->body->close();
});

it('rejects unallowlisted and credential-bearing redirect targets before a second request', function (string $location): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(302, ['Location' => [$location]]),
    ]);

    expect(fn () => $executor->stream(new DownloadInvoicePdfRequest('9')))
        ->toThrow(ProtocolViolation::class)
        ->and($sender->requests())->toHaveCount(1);
})->with([
    'unallowlisted host' => 'https://other.example.test/file.pdf',
    'token in query' => 'https://files.fakturownia.invalid/file.pdf?api_token=stolen',
    'userinfo' => 'https://user@files.fakturownia.invalid/file.pdf',
    'non HTTPS scheme' => 'http://files.fakturownia.invalid/file.pdf',
]);

it('fails closed for subclasses forged exact tuples and payment detail', function (JsonReadRequest $request): void {
    [$executor, $sender] = rt3AdapterFixture([]);

    expect(fn () => $executor->execute($request))
        ->toThrow(UnsupportedCapability::class)
        ->and($sender->requests())->toBe([]);
})->with([
    'unlisted subclass' => fn (): JsonReadRequest => new UnlistedInvoiceReadRequest,
    'forged final descriptor' => fn (): JsonReadRequest => forgedInvoiceReadRequest('/clients/42.json'),
    'unverified payment detail' => fn (): JsonReadRequest => new GetPaymentRequest('42'),
]);

it('maps raw sender failures to a redacted transport exception without previous throwable', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::failure('in-memory-contract-token at tenant.fakturownia.invalid'),
    ]);

    try {
        $executor->execute(new GetInvoiceRequest('42'));
    } catch (TransportFailed $exception) {
        expect($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('in-memory-contract-token')
            ->and($exception->getMessage())->not->toContain('tenant.fakturownia.invalid')
            ->and((string) $exception)->not->toContain('in-memory-contract-token')
            ->and($sender->requests())->toHaveCount(1);

        return;
    }

    throw new RuntimeException('The unsafe sender failure was not mapped.');
});

it('rejects global Saloon mocks before transport dispatch', function (): void {
    [$executor, $sender] = rt3AdapterFixture([
        InMemorySaloonExchange::response(200, ['Content-Type' => ['application/json']], '{"id":42}'),
    ]);
    MockClient::global([]);

    expect(fn () => $executor->execute(new GetInvoiceRequest('42')))
        ->toThrow(TransportFailed::class)
        ->and($sender->requests())->toBe([]);
});

it('keeps direct and typed production executors disabled while evidence is pending', function (): void {
    $sender = new InMemorySaloonSender([
        InMemorySaloonExchange::response(200, ['Content-Type' => ['application/json']], '{"id":42}'),
    ]);
    $baseUrl = BaseUrl::fromString(
        'https://tenant.fakturownia.pl',
        ['tenant.fakturownia.pl'],
    );
    $directExecutor = new SealedSaloonReadRequestExecutor(
        new PendingMatrixReadConnector($sender),
        $baseUrl,
    );
    $client = (new ConnectionConfig(
        $baseUrl,
        SecretValue::fromPlaintext('isolated-token'),
        3,
        8,
    ))->createClient();

    expect(fn () => $directExecutor->execute(new GetInvoiceRequest('42')))
        ->toThrow(UnsupportedCapability::class)
        ->and($sender->requests())->toBe([])
        ->and(fn () => $client->read()->invoices()->get('42'))
        ->toThrow(UnsupportedCapability::class);
});

/**
 * @param  list<InMemorySaloonExchange>  $exchanges
 * @return array{InMemorySaloonReadRequestExecutor, InMemorySaloonSender}
 */
function rt3AdapterFixture(array $exchanges): array
{
    $sender = new InMemorySaloonSender($exchanges);

    return [new InMemorySaloonReadRequestExecutor($sender), $sender];
}

function forgedInvoiceReadRequest(string $path): JsonReadRequest
{
    $reflection = new ReflectionClass(GetInvoiceRequest::class);
    $request = $reflection->newInstanceWithoutConstructor();
    $values = [
        'operationName' => ReadCapability::InvoiceGet->value,
        'requiredCapability' => ReadCapability::InvoiceGet,
        'endpointPath' => $path,
        'queryParameters' => new QueryParameters,
        'responseByteLimit' => 8_388_608,
        'retrySafety' => ReadSafety::Safe,
    ];

    foreach ($values as $property => $value) {
        (new ReflectionProperty(JsonReadRequest::class, $property))->setValue($request, $value);
    }

    return $request;
}

final readonly class UnlistedInvoiceReadRequest extends JsonReadRequest
{
    public function __construct()
    {
        parent::__construct(
            ReadCapability::InvoiceGet->value,
            ReadCapability::InvoiceGet,
            '/invoices/42.json',
            new QueryParameters,
        );
    }
}

final class PendingMatrixReadConnector extends Connector
{
    public ?int $tries = 1;

    public bool $allowBaseUrlOverride = false;

    public function __construct(InMemorySaloonSender $sender)
    {
        $this->sender = $sender;
        $this->authenticate(new class implements Authenticator
        {
            public function set(PendingRequest $pendingRequest): void
            {
                $pendingRequest->query()->add('api_token', 'must-not-be-dispatched');
            }
        });
    }

    public function resolveBaseUrl(): string
    {
        return 'https://tenant.fakturownia.pl';
    }

    /** @return array<string, bool|int> */
    protected function defaultConfig(): array
    {
        return [
            'allow_redirects' => false,
            'connect_timeout' => 3,
            'http_errors' => false,
            'stream' => true,
            'timeout' => 8,
            'verify' => true,
        ];
    }
}
