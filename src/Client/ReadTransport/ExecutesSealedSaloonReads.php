<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport;

use Cieplik206\Fakturownia\Client\ReadTransport\Testing\InMemorySaloonReadRequestExecutor;
use Cieplik206\Fakturownia\Client\ReadTransport\Testing\InMemorySaloonSender;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SaloonRuntimeIsolationGuard;
use Cieplik206\Fakturownia\Read\Exceptions\FakturowniaReadException;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceAttachmentsZipRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefUpoRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefXmlRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoicePdfRequest;
use Cieplik206\Fakturownia\Read\Requests\FindInvoicesByExactOidRequest;
use Cieplik206\Fakturownia\Read\Requests\GetClientRequest;
use Cieplik206\Fakturownia\Read\Requests\GetInvoiceRequest;
use Cieplik206\Fakturownia\Read\Requests\GetPaymentRequest;
use Cieplik206\Fakturownia\Read\Requests\GetProductRequest;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\ListClientsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListInvoicesRequest;
use Cieplik206\Fakturownia\Read\Requests\ListPaymentsRequest;
use Cieplik206\Fakturownia\Read\Requests\ListProductsRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use Cieplik206\Fakturownia\Read\ValueObjects\RedirectPolicy;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Saloon\Contracts\Authenticator;
use Saloon\Contracts\Sender;
use Saloon\Enums\Method;
use Saloon\Helpers\MiddlewarePipeline;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

/** @internal */
trait ExecutesSealedSaloonReads
{
    private const MaximumLocationBytes = 8192;

    private const MaximumPathBytes = 2048;

    private const ReadChunkBytes = 65_536;

    /** @var non-empty-list<int> */
    private const RedirectStatuses = [301, 302, 303, 307, 308];

    private readonly SensitiveParameterValue $connector;

    private readonly SensitiveParameterValue $baseUrl;

    private readonly SensitiveParameterValue $sender;

    private readonly SensitiveParameterValue $authenticator;

    /**
     * @var array{
     *     allow_redirects: false,
     *     connect_timeout: int,
     *     http_errors: false,
     *     stream: true,
     *     timeout: int,
     *     verify: true
     * }
     */
    private readonly array $connectorConfig;

    private function executeSealedRead(JsonReadRequest $request): JsonReadResponse
    {
        $this->assertJsonRequestContract($request);
        $this->assertTransportExecutionAuthorized($request->capability());

        try {
            $response = $this->dispatch(
                $this->connector(),
                $this->transportRequest(
                    $request->path(),
                    $request->query()->all(),
                    'application/json',
                ),
                $request->operation(),
                $this->baseUrl()->host(),
                true,
                $request->capability(),
            );

            return new JsonReadResponse(
                $response->status(),
                $this->responseHeaders($response),
                $this->readBoundedJsonBody($request, $response),
            );
        } catch (FakturowniaReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TransportFailed($request->operation());
        }
    }

    private function streamSealedRead(StreamReadRequest $request): StreamReadResponse
    {
        $this->assertStreamRequestContract($request);
        $this->assertTransportExecutionAuthorized($request->capability());

        try {
            return $this->dispatchStream($request);
        } catch (FakturowniaReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TransportFailed($request->operation());
        }
    }

    /** @return array{transport: string, credentials: string} */
    public function __debugInfo(): array
    {
        return ['transport' => 'sealed-saloon-read', 'credentials' => '[REDACTED]'];
    }

    /** @return array{transport: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Credentialed read executors cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Credentialed read executors cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Credentialed read executors cannot be unserialized.');
    }

    private function dispatchStream(StreamReadRequest $request): StreamReadResponse
    {
        $baseUrl = $this->baseUrl();
        $currentUri = new Uri((string) $baseUrl.$request->path());
        $currentConnector = $this->connector();
        $currentHost = $baseUrl->host();
        $credentialsAttached = true;
        $crossHostRedirected = false;
        $redirectCount = 0;

        while (true) {
            $transportRequest = $redirectCount === 0
                ? $this->transportRequest(
                    $request->path(),
                    $request->query()->all(),
                    implode(', ', $request->format->acceptedContentTypes()),
                )
                : $this->transportRequest(
                    $currentUri->getPath(),
                    [],
                    implode(', ', $request->format->acceptedContentTypes()),
                    $currentUri->getQuery(),
                );
            $response = $this->dispatch(
                $currentConnector,
                $transportRequest,
                $request->operation(),
                $currentHost,
                $credentialsAttached,
                $request->capability(),
            );

            if (! in_array($response->status(), self::RedirectStatuses, true)) {
                return new StreamReadResponse(
                    $response->status(),
                    $this->responseHeaders($response),
                    new PsrReadBodyStream($response->getPsrResponse()->getBody()),
                    $redirectCount,
                    $crossHostRedirected,
                    ! $crossHostRedirected || ! $credentialsAttached,
                );
            }

            try {
                $headers = new ReadHeaders($this->responseHeaders($response));
            } catch (Throwable $exception) {
                $this->closeResponseSilently($response);

                throw $exception;
            }

            if ($request->redirectPolicy === RedirectPolicy::Deny) {
                $this->closeResponseSilently($response);

                throw new ProtocolViolation(
                    $request->operation(),
                    'redirect policy',
                    $response->status(),
                    $headers->providerRequestId(),
                );
            }

            if ($redirectCount >= $request->maximumRedirects) {
                $this->closeResponseSilently($response);

                throw new ProtocolViolation(
                    $request->operation(),
                    'redirect count',
                    $response->status(),
                    $headers->providerRequestId(),
                );
            }

            try {
                $target = $this->redirectTarget($request, $response, $currentUri, $headers);
            } catch (Throwable $exception) {
                $this->closeResponseSilently($response);

                throw $exception;
            }
            $targetHost = strtolower($target->getHost());
            $targetIsOriginalHost = hash_equals($baseUrl->host(), $targetHost);

            if (! $targetIsOriginalHost && $request->redirectPolicy !== RedirectPolicy::CrossHostWithoutCredentials) {
                $this->closeResponseSilently($response);

                throw new ProtocolViolation(
                    $request->operation(),
                    'redirect host',
                    $response->status(),
                    $headers->providerRequestId(),
                );
            }

            $this->closeResponse($response);
            $redirectCount++;
            $currentUri = $target;
            $currentHost = $targetHost;

            if (! $targetIsOriginalHost) {
                $crossHostRedirected = true;
                $credentialsAttached = false;
            }

            if (! $credentialsAttached) {
                $currentConnector = $this->credentiallessConnector($targetHost);
            }
        }
    }

    private function dispatch(
        Connector $connector,
        Request $request,
        string $operation,
        string $expectedHost,
        bool $credentialsAttached,
        ReadCapability $capability,
    ): Response {
        $this->assertTransportExecutionAuthorized($capability);
        SaloonRuntimeIsolationGuard::assertIsolated();
        $expectedAuthenticator = $credentialsAttached ? $this->authenticator() : null;
        $this->assertConnectorState($connector, $expectedHost, $expectedAuthenticator);
        $this->assertTransportRequestState($request);
        $pendingRequest = $connector->createPendingRequest($request);
        $this->assertPendingRequestState($pendingRequest, $connector, $request, $expectedHost, $credentialsAttached);

        try {
            $response = $this->sender()->send($pendingRequest);

            return $pendingRequest->executeResponsePipeline($response);
        } catch (FakturowniaReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TransportFailed($operation);
        }
    }

    /**
     * @param  array<string, bool|int|string|list<bool|int|string>>  $query
     */
    private function transportRequest(
        #[SensitiveParameter] string $path,
        #[SensitiveParameter] array $query,
        string $accept,
        #[SensitiveParameter] ?string $rawQuery = null,
    ): Request {
        return new class($path, new QueryParameters($query), $accept, $rawQuery) extends Request
        {
            protected Method $method = Method::GET;

            private readonly SensitiveParameterValue $path;

            private readonly QueryParameters $queryParameters;

            private readonly SensitiveParameterValue $rawQuery;

            private readonly string $accept;

            public function __construct(
                #[SensitiveParameter] string $path,
                QueryParameters $query,
                string $accept,
                #[SensitiveParameter] ?string $rawQuery,
            ) {
                $this->path = new SensitiveParameterValue($path);
                $this->queryParameters = $query;
                $this->rawQuery = new SensitiveParameterValue($rawQuery);
                $this->accept = $accept;
                $this->tries = 1;
                $this->allowBaseUrlOverride = false;
            }

            public function resolveEndpoint(): string
            {
                $path = $this->path->getValue();

                if (! is_string($path)) {
                    throw new LogicException('The sealed read endpoint is corrupted.');
                }

                return $path;
            }

            public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
            {
                $rawQuery = $this->rawQuery->getValue();

                if ($rawQuery === null) {
                    return $request;
                }

                if (! is_string($rawQuery)) {
                    throw new LogicException('The sealed redirect query is corrupted.');
                }

                $authenticationQuery = $request->getUri()->getQuery();
                $query = $rawQuery;

                if ($authenticationQuery !== '') {
                    $query = $query === '' ? $authenticationQuery : "{$query}&{$authenticationQuery}";
                }

                return $request->withUri($request->getUri()->withQuery($query));
            }

            /** @return array<string, bool|int|string|list<bool|int|string>> */
            protected function defaultQuery(): array
            {
                return $this->queryParameters->all();
            }

            /** @return array{Accept: string} */
            protected function defaultHeaders(): array
            {
                return ['Accept' => $this->accept];
            }

            /** @return array{endpoint: string, query: string, credentials: string} */
            public function __debugInfo(): array
            {
                return [
                    'endpoint' => '[REDACTED]',
                    'query' => '[REDACTED]',
                    'credentials' => '[REDACTED]',
                ];
            }

            /** @return never */
            public function __clone()
            {
                throw new LogicException('Sealed transport requests cannot be cloned.');
            }
        };
    }

    private function credentiallessConnector(#[SensitiveParameter] string $host): Connector
    {
        if (! $this->baseUrl()->allowsHost($host)) {
            throw new LogicException('The redirect host is not part of the exact connection policy.');
        }

        return new class($host, $this->connectorConfig['connect_timeout'], $this->connectorConfig['timeout'], $this->sender()) extends Connector
        {
            public ?int $tries = 1;

            public bool $allowBaseUrlOverride = false;

            private readonly SensitiveParameterValue $origin;

            public function __construct(
                #[SensitiveParameter] string $host,
                private readonly int $connectTimeoutSeconds,
                private readonly int $requestTimeoutSeconds,
                Sender $sender,
            ) {
                $this->origin = new SensitiveParameterValue("https://{$host}");
                $this->sender = $sender;
            }

            public function resolveBaseUrl(): string
            {
                $origin = $this->origin->getValue();

                if (! is_string($origin)) {
                    throw new LogicException('The credentialless redirect origin is corrupted.');
                }

                return $origin;
            }

            /** @return array<string, bool|int> */
            protected function defaultConfig(): array
            {
                return [
                    'allow_redirects' => false,
                    'connect_timeout' => $this->connectTimeoutSeconds,
                    'http_errors' => false,
                    'stream' => true,
                    'timeout' => $this->requestTimeoutSeconds,
                    'verify' => true,
                ];
            }

            /** @return array{base_url: string, credentials: string} */
            public function __debugInfo(): array
            {
                return ['base_url' => '[REDACTED]', 'credentials' => '[REDACTED]'];
            }

            /** @return never */
            public function __clone()
            {
                throw new LogicException('Credentialless redirect connectors cannot be cloned.');
            }
        };
    }

    private function redirectTarget(
        StreamReadRequest $request,
        Response $response,
        UriInterface $currentUri,
        ReadHeaders $headers,
    ): UriInterface {
        $locations = $response->getPsrResponse()->getHeader('Location');

        if (count($locations) !== 1
            || $locations[0] === ''
            || strlen($locations[0]) > self::MaximumLocationBytes
            || trim($locations[0]) !== $locations[0]
            || preg_match('/[\x00-\x20\x7F\\\\]/', $locations[0]) === 1) {
            throw new ProtocolViolation(
                $request->operation(),
                'single safe redirect location',
                $response->status(),
                $headers->providerRequestId(),
            );
        }

        try {
            $target = UriResolver::resolve($currentUri, new Uri($locations[0]));
        } catch (Throwable) {
            throw new ProtocolViolation(
                $request->operation(),
                'redirect URI',
                $response->status(),
                $headers->providerRequestId(),
            );
        }

        $path = $target->getPath();
        $decodedPath = rawurldecode($path);

        if (strtolower($target->getScheme()) !== 'https'
            || $target->getHost() === ''
            || $target->getPort() !== null
            || $target->getUserInfo() !== ''
            || $target->getFragment() !== ''
            || ! $this->baseUrl()->allowsHost($target->getHost())
            || $path === ''
            || strlen($path) > self::MaximumPathBytes
            || preg_match('/%(?![A-Fa-f0-9]{2})/', $path) === 1
            || str_contains($decodedPath, '..')
            || str_contains($decodedPath, '//')
            || str_contains($decodedPath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decodedPath) === 1
            || $this->containsCredentialQuery($target->getQuery())) {
            throw new ProtocolViolation(
                $request->operation(),
                'redirect target',
                $response->status(),
                $headers->providerRequestId(),
            );
        }

        return $target;
    }

    private function containsCredentialQuery(#[SensitiveParameter] string $query): bool
    {
        if ($query === '') {
            return false;
        }

        if (strlen($query) > self::MaximumLocationBytes
            || preg_match('/%(?![A-Fa-f0-9]{2})/', $query) === 1
            || preg_match('/[\x00-\x20\x7F]/', $query) === 1) {
            return true;
        }

        $credentials = [
            'access_token',
            'api-key',
            'api_key',
            'api_token',
            'apikey',
            'auth',
            'authorization',
            'bearer',
            'token',
        ];

        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            if ($parameter === '') {
                return true;
            }

            $encodedName = explode('=', $parameter, 2)[0];
            $name = strtolower(urldecode($encodedName));
            $name = preg_replace('/\[.*$/', '', $name) ?? $name;

            if (in_array($name, $credentials, true)) {
                return true;
            }
        }

        return false;
    }

    private function readBoundedJsonBody(JsonReadRequest $request, Response $response): string
    {
        $stream = $response->getPsrResponse()->getBody();
        $body = '';
        $maximumBytes = $request->maximumResponseBytes();

        try {
            while (! $stream->eof() && strlen($body) <= $maximumBytes) {
                $remaining = ($maximumBytes + 1) - strlen($body);
                $chunk = $stream->read(min(self::ReadChunkBytes, $remaining));

                if ($chunk === '') {
                    throw new TransportFailed($request->operation());
                }

                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }

        if (strlen($body) > $maximumBytes) {
            throw new ProtocolViolation(
                $request->operation(),
                'maximum JSON body size',
                $response->status(),
                (new ReadHeaders($this->responseHeaders($response)))->providerRequestId(),
            );
        }

        return $body;
    }

    private function closeResponse(Response $response): void
    {
        $response->getPsrResponse()->getBody()->close();
    }

    private function closeResponseSilently(Response $response): void
    {
        try {
            $this->closeResponse($response);
        } catch (Throwable) {
        }
    }

    /** @return array<string, list<string>> */
    private function responseHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->getPsrResponse()->getHeaders() as $name => $values) {
            $headers[$name] = array_values($values);
        }

        return $headers;
    }

    private function assertJsonRequestContract(JsonReadRequest $request): void
    {
        $class = $request::class;

        if ($class === GetPaymentRequest::class) {
            throw new UnsupportedCapability(ReadCapability::PaymentGet);
        }

        $valid = match ($class) {
            GetInvoiceRequest::class => $this->isGetRequest(
                $request,
                ReadCapability::InvoiceGet,
                '#^/invoices/[1-9][0-9]{0,39}\.json$#D',
            ),
            GetClientRequest::class => $this->isGetRequest(
                $request,
                ReadCapability::ClientGet,
                '#^/clients/[1-9][0-9]{0,39}\.json$#D',
            ),
            GetProductRequest::class => $this->isGetRequest(
                $request,
                ReadCapability::ProductGet,
                '#^/products/[1-9][0-9]{0,39}\.json$#D',
            ),
            ListInvoicesRequest::class => $this->isInvoiceListRequest($request),
            FindInvoicesByExactOidRequest::class => $this->isExactOidInvoiceRequest($request),
            ListClientsRequest::class => $this->isClientListRequest($request),
            ListProductsRequest::class => $this->isProductListRequest($request),
            ListPaymentsRequest::class => $this->isPaymentListRequest($request),
            default => false,
        };

        if (! $valid) {
            throw new UnsupportedCapability($request->capability());
        }
    }

    private function assertStreamRequestContract(StreamReadRequest $request): void
    {
        $class = $request::class;
        $valid = match ($class) {
            DownloadInvoicePdfRequest::class => $this->isStreamRequest(
                $request,
                ReadCapability::InvoicePdfStream,
                ArtifactFormat::Pdf,
                '#^/invoices/[1-9][0-9]{0,39}\.pdf$#D',
                [],
                20_971_520,
            ),
            DownloadInvoiceAttachmentsZipRequest::class => $this->isStreamRequest(
                $request,
                ReadCapability::InvoiceAttachmentsZipStream,
                ArtifactFormat::Zip,
                '#^/invoices/[1-9][0-9]{0,39}/attachments_zip\.json$#D',
                [],
                52_428_800,
            ),
            DownloadInvoiceKsefXmlRequest::class => $this->isStreamRequest(
                $request,
                ReadCapability::InvoiceKsefXmlStream,
                ArtifactFormat::Xml,
                '#^/invoices/[1-9][0-9]{0,39}/attachment$#D',
                ['kind' => 'gov'],
                10_485_760,
            ),
            DownloadInvoiceKsefUpoRequest::class => $this->isStreamRequest(
                $request,
                ReadCapability::InvoiceKsefUpoStream,
                ArtifactFormat::Upo,
                '#^/invoices/[1-9][0-9]{0,39}/attachment$#D',
                ['kind' => 'gov_upo'],
                10_485_760,
            ),
            default => false,
        };

        if (! $valid) {
            throw new UnsupportedCapability($request->capability());
        }
    }

    private function isGetRequest(JsonReadRequest $request, ReadCapability $capability, string $pathPattern): bool
    {
        return $this->hasCommonContract($request, $capability, 8_388_608)
            && preg_match($pathPattern, $request->path()) === 1
            && $request->query()->all() === [];
    }

    private function isInvoiceListRequest(JsonReadRequest $request): bool
    {
        $query = $request->query()->all();
        $allowed = [
            'client_id', 'date_from', 'date_to', 'from_invoice_id', 'include_positions', 'income', 'kind', 'kinds',
            'number', 'order', 'page', 'per_page', 'period', 'search_date_type', 'warehouse_id',
        ];

        if (! $this->hasCommonContract($request, ReadCapability::InvoiceList, 8_388_608)
            || $request->path() !== '/invoices.json'
            || ! $this->hasOnlyQueryKeys($query, $allowed, ['page', 'per_page', 'order'])
            || ! $this->hasValidPagination($query)
            || ! is_string($query['order'])
            || preg_match('/^[a-z][a-z0-9_]{0,63}\.(?:asc|desc)$/D', $query['order']) !== 1) {
            return false;
        }

        foreach (['client_id', 'warehouse_id', 'from_invoice_id'] as $key) {
            if (isset($query[$key]) && (! is_string($query[$key]) || preg_match('/^[1-9][0-9]{0,39}$/D', $query[$key]) !== 1)) {
                return false;
            }
        }

        foreach (['date_from', 'date_to'] as $key) {
            if (isset($query[$key]) && (! is_string($query[$key]) || ! $this->isApiDate($query[$key]))) {
                return false;
            }
        }

        foreach (['period', 'kind', 'search_date_type'] as $key) {
            if (isset($query[$key]) && (! is_string($query[$key]) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $query[$key]) !== 1)) {
                return false;
            }
        }

        if ((isset($query['date_from']) || isset($query['date_to'])) && ! isset($query['period'])) {
            return false;
        }

        if (isset($query['number'])
            && (! is_string($query['number']) || $query['number'] === '' || strlen($query['number']) > 200 || preg_match('//u', $query['number']) !== 1)) {
            return false;
        }

        if (isset($query['income']) && ! in_array($query['income'], ['yes', 'no'], true)) {
            return false;
        }

        if (isset($query['include_positions']) && $query['include_positions'] !== 'true') {
            return false;
        }

        if (isset($query['kind'], $query['kinds'])) {
            return false;
        }

        if (isset($query['kinds']) && ! $this->isKindList($query['kinds'])) {
            return false;
        }

        return true;
    }

    private function isExactOidInvoiceRequest(JsonReadRequest $request): bool
    {
        $query = $request->query()->all();
        $required = [
            'date_from', 'date_to', 'include_positions', 'income', 'kind', 'oid', 'order', 'page', 'per_page',
            'period', 'search_date_type',
        ];

        return $this->hasCommonContract($request, ReadCapability::InvoiceList, 8_388_608)
            && $request->path() === '/invoices.json'
            && $this->hasOnlyQueryKeys($query, $required, $required)
            && $this->hasValidPagination($query)
            && is_string($query['oid'])
            && trim($query['oid']) !== ''
            && strlen($query['oid']) <= 256
            && preg_match('//u', $query['oid']) === 1
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $query['oid']) !== 1
            && is_string($query['kind'])
            && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $query['kind']) === 1
            && in_array($query['income'], ['yes', 'no'], true)
            && $query['include_positions'] === 'true'
            && $query['period'] === 'more'
            && is_string($query['date_from'])
            && is_string($query['date_to'])
            && $query['date_from'] === $query['date_to']
            && $this->isApiDate($query['date_from'])
            && $query['search_date_type'] === 'issue_date'
            && $query['order'] === 'id.asc';
    }

    private function isClientListRequest(JsonReadRequest $request): bool
    {
        $query = $request->query()->all();

        if (! $this->hasCommonContract($request, ReadCapability::ClientList, 8_388_608)
            || $request->path() !== '/clients.json'
            || ! $this->hasOnlyQueryKeys(
                $query,
                ['email', 'external_id', 'name', 'page', 'per_page', 'tax_no'],
                ['page', 'per_page'],
            )
            || ! $this->hasValidPagination($query)) {
            return false;
        }

        foreach (['email', 'external_id', 'name', 'tax_no'] as $key) {
            if (isset($query[$key])
                && (! is_string($query[$key]) || $query[$key] === '' || strlen($query[$key]) > 512 || preg_match('//u', $query[$key]) !== 1)) {
                return false;
            }
        }

        return true;
    }

    private function isProductListRequest(JsonReadRequest $request): bool
    {
        $query = $request->query()->all();

        return $this->hasCommonContract($request, ReadCapability::ProductList, 8_388_608)
            && $request->path() === '/products.json'
            && $this->hasOnlyQueryKeys($query, ['page', 'per_page'], ['page', 'per_page'])
            && $this->hasValidPagination($query);
    }

    private function isPaymentListRequest(JsonReadRequest $request): bool
    {
        $query = $request->query()->all();

        return $this->hasCommonContract($request, ReadCapability::PaymentList, 8_388_608)
            && $request->path() === '/banking/payments.json'
            && $this->hasOnlyQueryKeys($query, ['include', 'page', 'per_page'], ['page', 'per_page'])
            && $this->hasValidPagination($query)
            && (! isset($query['include']) || $query['include'] === 'invoices');
    }

    /**
     * @param  array<string, bool|int|string|list<bool|int|string>>  $expectedQuery
     */
    private function isStreamRequest(
        StreamReadRequest $request,
        ReadCapability $capability,
        ArtifactFormat $format,
        string $pathPattern,
        array $expectedQuery,
        int $maximumResponseBytes,
    ): bool {
        return $this->hasCommonContract($request, $capability, $maximumResponseBytes)
            && $request->format === $format
            && preg_match($pathPattern, $request->path()) === 1
            && $request->query()->all() === $expectedQuery
            && $request->redirectPolicy === RedirectPolicy::CrossHostWithoutCredentials
            && $request->maximumRedirects === 3;
    }

    private function hasCommonContract(
        JsonReadRequest|StreamReadRequest $request,
        ReadCapability $capability,
        int $maximumResponseBytes,
    ): bool {
        return $request->capability() === $capability
            && $request->operation() === $capability->value
            && $request->safety() === ReadSafety::Safe
            && $request->maximumResponseBytes() === $maximumResponseBytes;
    }

    /**
     * @param  array<string, bool|int|string|list<bool|int|string>>  $query
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function hasOnlyQueryKeys(array $query, array $allowed, array $required): bool
    {
        foreach (array_keys($query) as $key) {
            if (! in_array($key, $allowed, true)) {
                return false;
            }
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $query)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, bool|int|string|list<bool|int|string>> $query */
    private function hasValidPagination(array $query): bool
    {
        return is_int($query['page'] ?? null)
            && $query['page'] >= 1
            && is_int($query['per_page'] ?? null)
            && $query['per_page'] >= 1
            && $query['per_page'] <= 100;
    }

    private function isApiDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function isKindList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        $seen = [];

        foreach ($value as $kind) {
            if (! is_string($kind)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $kind) !== 1
                || isset($seen[$kind])) {
                return false;
            }

            $seen[$kind] = true;
        }

        return true;
    }

    /**
     * @return array{
     *     allow_redirects: false,
     *     connect_timeout: int,
     *     http_errors: false,
     *     stream: true,
     *     timeout: int,
     *     verify: true
     * }
     */
    private function validatedConnectorConfig(Connector $connector): array
    {
        $config = $connector->config()->all();

        if (array_keys($config) !== [
            'allow_redirects',
            'connect_timeout',
            'http_errors',
            'stream',
            'timeout',
            'verify',
        ]
            || $config['allow_redirects'] !== false
            || ! is_int($config['connect_timeout'])
            || $config['connect_timeout'] < 1
            || $config['connect_timeout'] > 60
            || $config['http_errors'] !== false
            || $config['stream'] !== true
            || ! is_int($config['timeout'])
            || $config['timeout'] < $config['connect_timeout']
            || $config['timeout'] > 300
            || $config['verify'] !== true) {
            throw new LogicException('The read connector transport configuration is not sealed.');
        }

        return [
            'allow_redirects' => false,
            'connect_timeout' => $config['connect_timeout'],
            'http_errors' => false,
            'stream' => true,
            'timeout' => $config['timeout'],
            'verify' => true,
        ];
    }

    private function assertConnectorState(
        Connector $connector,
        #[SensitiveParameter] string $expectedHost,
        ?Authenticator $expectedAuthenticator,
    ): void {
        if ($connector->sender() !== $this->sender()
            || $connector->getAuthenticator() !== $expectedAuthenticator
            || $connector->getMockClient() !== null
            || $connector->allowBaseUrlOverride
            || $connector->tries !== 1
            || $connector->headers()->all() !== []
            || $connector->query()->all() !== []
            || $connector->config()->all() !== $this->connectorConfig
            || $connector->resolveBaseUrl() !== "https://{$expectedHost}"
            || ! $this->hasEmptyMiddleware($connector->middleware())) {
            throw new LogicException('The read connector state changed after it was sealed.');
        }
    }

    private function assertTransportRequestState(Request $request): void
    {
        if ($request->getMethod() !== Method::GET
            || $request->allowBaseUrlOverride !== false
            || $request->tries !== 1
            || $request->getAuthenticator() !== null
            || $request->getMockClient() !== null
            || $request->config()->all() !== []
            || ! $this->hasEmptyMiddleware($request->middleware())) {
            throw new LogicException('The sealed read transport request was mutated.');
        }
    }

    private function assertPendingRequestState(
        PendingRequest $pendingRequest,
        Connector $connector,
        Request $request,
        #[SensitiveParameter] string $expectedHost,
        bool $credentialsAttached,
    ): void {
        $uri = $pendingRequest->getUri();
        $query = $pendingRequest->query()->all();
        $apiToken = $query['api_token'] ?? null;

        if ($pendingRequest->getConnector() !== $connector
            || $pendingRequest->getRequest() !== $request
            || $pendingRequest->hasFakeResponse()
            || $pendingRequest->body() !== null
            || $pendingRequest->getMethod() !== Method::GET
            || strtolower($uri->getScheme()) !== 'https'
            || ! hash_equals($expectedHost, strtolower($uri->getHost()))
            || $uri->getPort() !== null
            || $uri->getUserInfo() !== ''
            || $uri->getFragment() !== ''
            || $pendingRequest->config()->all() !== $this->connectorConfig
            || $pendingRequest->headers()->get('Authorization') !== null
            || $pendingRequest->headers()->get('Cookie') !== null
            || ($credentialsAttached && (! is_string($apiToken) || $apiToken === ''))
            || (! $credentialsAttached && $apiToken !== null)) {
            throw new LogicException('The pending read request violated the sealed transport contract.');
        }
    }

    private function hasEmptyMiddleware(MiddlewarePipeline $middleware): bool
    {
        return $middleware->getRequestPipeline()->getPipes() === []
            && $middleware->getResponsePipeline()->getPipes() === []
            && $middleware->getFatalPipeline()->getPipes() === [];
    }

    private function assertTransportExecutionAuthorized(ReadCapability $capability): void
    {
        if ($this->sender()::class === InMemorySaloonSender::class
            && hash_equals(InMemorySaloonReadRequestExecutor::OriginHost, $this->baseUrl()->host())) {
            return;
        }

        (new PinnedReadCapabilityGate)->assertSupported($capability);
    }

    private function connector(): Connector
    {
        $connector = $this->connector->getValue();

        if (! $connector instanceof Connector) {
            throw new LogicException('The credentialed read connector is corrupted.');
        }

        return $connector;
    }

    private function baseUrl(): BaseUrl
    {
        $baseUrl = $this->baseUrl->getValue();

        if (! $baseUrl instanceof BaseUrl) {
            throw new LogicException('The read base URL policy is corrupted.');
        }

        return $baseUrl;
    }

    private function sender(): Sender
    {
        $sender = $this->sender->getValue();

        if (! $sender instanceof Sender) {
            throw new LogicException('The sealed read sender is corrupted.');
        }

        return $sender;
    }

    private function authenticator(): Authenticator
    {
        $authenticator = $this->authenticator->getValue();

        if (! $authenticator instanceof Authenticator) {
            throw new LogicException('The sealed read authenticator is corrupted.');
        }

        return $authenticator;
    }
}
