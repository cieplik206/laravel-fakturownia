<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResponse;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConcurrentBrokeredEffectExecutionProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerSession;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Saloon\Contracts\Sender;
use Saloon\Data\FactoryCollection;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Http\Senders\Factories\GuzzleMultipartBodyFactory;
use SensitiveParameter;

final class NativeBrokerSaloonSender implements Sender
{
    public const TokenSentinel = 'native-broker-credential-unavailable';

    /** @var array<string, int> */
    private array $effectSequences = [];

    /** @var list<array<string, mixed>> */
    private array $effectExecutionReceipts = [];

    public function __construct(#[SensitiveParameter] private NativeBrokerSession $session) {}

    public function getFactoryCollection(): FactoryCollection
    {
        $factory = new HttpFactory;

        return new FactoryCollection(
            requestFactory: $factory,
            uriFactory: $factory,
            streamFactory: $factory,
            responseFactory: $factory,
            multipartBodyFactory: new GuzzleMultipartBodyFactory,
        );
    }

    public function session(): NativeBrokerSession
    {
        return $this->session;
    }

    /** @return list<array<string, mixed>> */
    public function effectExecutionReceipts(): array
    {
        return $this->effectExecutionReceipts;
    }

    public function send(#[SensitiveParameter] PendingRequest $pendingRequest): Response
    {
        $mapped = $this->map($pendingRequest);

        if ($mapped['kind'] === 'read') {
            $proposal = BrokeredReadObservationProposal::fromArray($mapped['proposal']);
            $observation = $this->session->observe($proposal)->result;

            if ($observation->disposition !== BrokeredReadObservationDisposition::Observed) {
                throw $this->transportFailure($pendingRequest->getRequest());
            }

            return $this->response(
                $pendingRequest,
                $observation->httpStatus,
                $observation->contentType,
                $observation->responseBody(),
            );
        }

        $proposal = BrokeredEffectExecutionProposal::fromArray($mapped['proposal']);
        $execution = $this->session->execute($proposal);
        $this->recordReceipt($execution);

        return $this->effectResponse($pendingRequest, $execution);
    }

    public function sendAsync(PendingRequest $pendingRequest): PromiseInterface
    {
        throw new LogicException('Native broker concurrency requires the exact same-OID two-request protocol.');
    }

    /**
     * @return array{Response|\Throwable, Response|\Throwable}
     */
    public function sendConcurrentSameOid(
        #[SensitiveParameter] PendingRequest $first,
        #[SensitiveParameter] PendingRequest $second,
    ): array {
        $firstMapped = $this->map($first);
        $secondMapped = $this->map($second);

        if ($firstMapped['kind'] !== 'effect' || $secondMapped['kind'] !== 'effect') {
            throw new InvalidArgumentException('Native same-OID concurrency accepts only mutating invoice requests.');
        }

        $batch = ConcurrentBrokeredEffectExecutionProposal::fromArray([
            'contract' => ConcurrentBrokeredEffectExecutionProposal::Contract,
            'version' => ConcurrentBrokeredEffectExecutionProposal::Version,
            'proposals' => [$firstMapped['proposal'], $secondMapped['proposal']],
        ]);
        $response = $this->session->executeConcurrent($batch);

        foreach ($response->responses as $execution) {
            $this->recordReceipt($execution);
        }

        return [
            $this->effectOutcome($first, $response->responses[0]),
            $this->effectOutcome($second, $response->responses[1]),
        ];
    }

    /**
     * @return array{
     *     kind: 'effect'|'read',
     *     proposal: array<string, int|string>
     * }
     */
    private function map(#[SensitiveParameter] PendingRequest $pendingRequest): array
    {
        $request = $pendingRequest->getRequest();
        [$profile, $targetKey] = $this->target($pendingRequest->getUrl());
        [$connectTimeoutMs, $requestTimeoutMs] = $this->timeouts($pendingRequest);

        if ($request instanceof AccountProbeRequest || $request instanceof AccountKsefDemoRequest) {
            $this->assertQuery($pendingRequest, ['api_token', 'integration_token']);

            return $this->read(
                $profile,
                $targetKey,
                'account.read',
                '/account.json',
                '/account.json',
                $connectTimeoutMs,
                $requestTimeoutMs,
                1_048_576,
            );
        }

        if ($request instanceof SearchProbeInvoicesRequest) {
            $query = $this->query($pendingRequest, [
                'api_token',
                'include_positions',
                'oid',
                'page',
                'per_page',
                'period',
            ]);

            if ($query['include_positions'] !== true
                || $query['per_page'] !== 100
                || $query['period'] !== 'all') {
                throw new InvalidArgumentException('The native S0.3 search query is not canonical.');
            }

            $providerPath = '/invoices.json?include_positions=true&oid='.
                $this->oid($query['oid']).'&page='.$this->page($query['page']).'&per_page=100&period=all';

            return $this->read(
                $profile,
                $targetKey,
                'invoice.search',
                '/invoices.json',
                $providerPath,
                $connectTimeoutMs,
                $requestTimeoutMs,
                1_048_576,
            );
        }

        if ($request instanceof SearchKsefDemoInvoicesRequest) {
            $query = $this->query($pendingRequest, ['api_token', 'oid', 'page', 'per_page', 'period']);

            if ($query['per_page'] !== 100 || $query['period'] !== 'all') {
                throw new InvalidArgumentException('The native S0.4 search query is not canonical.');
            }

            $providerPath = '/invoices.json?oid='.$this->oid($query['oid']).
                '&page='.$this->page($query['page']).'&per_page=100&period=all';

            return $this->read(
                $profile,
                $targetKey,
                'invoice.search',
                '/invoices.json',
                $providerPath,
                $connectTimeoutMs,
                $requestTimeoutMs,
                1_048_576,
            );
        }

        if ($request instanceof ReadKsefDemoInvoiceRequest) {
            $query = $this->query($pendingRequest, ['api_token', 'fields[invoice]']);

            if ($query['fields[invoice]'] !== 'id,gov_status,gov_id,gov_error_messages') {
                throw new InvalidArgumentException('The native KSeF read field selection is not canonical.');
            }

            $path = $this->canonicalDocumentPath($request, '.json').
                '?fields%5Binvoice%5D=id%2Cgov_status%2Cgov_id%2Cgov_error_messages';

            return $this->read(
                $profile,
                $targetKey,
                'invoice.read',
                '/invoices/{invoice_id}.json',
                $path,
                $connectTimeoutMs,
                $requestTimeoutMs,
                1_048_576,
            );
        }

        if ($request instanceof DownloadKsefDemoPdfRequest) {
            $this->assertQuery($pendingRequest, ['api_token']);

            return $this->read(
                $profile,
                $targetKey,
                'invoice.pdf.download',
                '/invoices/{invoice_id}.pdf',
                $this->canonicalDocumentPath($request, '.pdf'),
                $connectTimeoutMs,
                $requestTimeoutMs,
                25 * 1024 * 1024,
            );
        }

        if ($request instanceof SendKsefDemoInvoiceRequest) {
            $query = $this->query($pendingRequest, ['api_token', 'fields[invoice]', 'send_to_ksef']);

            if ($query['send_to_ksef'] !== 'yes'
                || $query['fields[invoice]'] !== 'id,gov_status,gov_id,gov_error_messages') {
                throw new InvalidArgumentException('The native KSeF send query is not canonical.');
            }

            return $this->effect(
                $profile,
                $targetKey,
                'invoice.ksef.ensure_accepted',
                'ksef_explicit_submit',
                'GET',
                '/invoices/{invoice_id}.json?send_to_ksef=yes',
                $this->canonicalDocumentPath($request, '.json').'?send_to_ksef=yes',
                '',
                $connectTimeoutMs,
                $requestTimeoutMs,
            );
        }

        if ($request instanceof CreateProbeInvoiceRequest
            || $request instanceof CreateTimedProbeInvoiceRequest
            || $request instanceof CreateKsefDemoInvoiceRequest) {
            $body = $this->body($pendingRequest);
            $ksef = $request instanceof CreateKsefDemoInvoiceRequest;

            return $this->effect(
                $profile,
                $targetKey,
                $ksef ? 'contract_probe.invoice.fixture.issue' : 'invoice.vat.issue',
                $ksef ? 'probe_fixture_invoice_create' : 'invoice_create',
                'POST',
                '/invoices.json',
                '/invoices.json',
                CanonicalCodec::encode(['invoice' => $body['invoice']]),
                $connectTimeoutMs,
                $requestTimeoutMs,
            );
        }

        throw new InvalidArgumentException('The Saloon request is not allowlisted by the native broker adapter.');
    }

    /**
     * @return array{kind: 'read', proposal: array<string, int|string>}
     */
    private function read(
        string $profile,
        string $targetKey,
        string $capability,
        string $endpointTemplate,
        string $providerPath,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
        int $maximumResponseBytes,
    ): array {
        return [
            'kind' => 'read',
            'proposal' => [
                'contract' => BrokeredReadObservationProposal::Contract,
                'version' => BrokeredReadObservationProposal::Version,
                'evidence_contract' => $this->session->authority->evidenceContract,
                'observation_id' => bin2hex(random_bytes(16)),
                'profile' => $profile,
                'target_key' => $targetKey,
                'capability' => $capability,
                'http_method' => 'GET',
                'endpoint_template' => $endpointTemplate,
                'provider_path' => $providerPath,
                'connect_timeout_ms' => $connectTimeoutMs,
                'request_timeout_ms' => $requestTimeoutMs,
                'maximum_response_bytes' => $maximumResponseBytes,
            ],
        ];
    }

    /**
     * @return array{kind: 'effect', proposal: array<string, int|string>}
     */
    private function effect(
        string $profile,
        string $targetKey,
        string $capability,
        string $semanticEffect,
        string $httpMethod,
        string $endpointTemplate,
        string $providerPath,
        #[SensitiveParameter] string $body,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ): array {
        $sequence = ($this->effectSequences[$capability] ?? 0) + 1;
        $this->effectSequences[$capability] = $sequence;

        return [
            'kind' => 'effect',
            'proposal' => [
                'contract' => BrokeredEffectExecutionProposal::Contract,
                'version' => BrokeredEffectExecutionProposal::Version,
                'evidence_contract' => $this->session->authority->evidenceContract,
                'effect_id' => bin2hex(random_bytes(16)),
                'effect_sequence' => $sequence,
                'profile' => $profile,
                'target_key' => $targetKey,
                'capability' => $capability,
                'semantic_effect' => $semanticEffect,
                'http_method' => $httpMethod,
                'endpoint_template' => $endpointTemplate,
                'provider_path' => $providerPath,
                'request_body_base64' => base64_encode($body),
                'connect_timeout_ms' => $connectTimeoutMs,
                'request_timeout_ms' => $requestTimeoutMs,
                'maximum_response_bytes' => 1_048_576,
            ],
        ];
    }

    private function effectOutcome(
        #[SensitiveParameter] PendingRequest $pendingRequest,
        #[SensitiveParameter] BrokeredEffectExecutionResponse $execution,
    ): Response|\Throwable {
        try {
            return $this->effectResponse($pendingRequest, $execution);
        } catch (\Throwable $exception) {
            return $exception;
        }
    }

    private function recordReceipt(#[SensitiveParameter] BrokeredEffectExecutionResponse $execution): void
    {
        $this->effectExecutionReceipts[] = $execution->receipt->toArray();
    }

    private function effectResponse(
        #[SensitiveParameter] PendingRequest $pendingRequest,
        #[SensitiveParameter] BrokeredEffectExecutionResponse $execution,
    ): Response {
        return match ($execution->result->disposition) {
            BrokeredEffectDisposition::Applied => $this->response(
                $pendingRequest,
                $execution->result->httpStatus,
                $execution->result->contentType,
                $execution->result->responseBody(),
            ),
            BrokeredEffectDisposition::PossiblyApplied => throw $this->transportFailure(
                $pendingRequest->getRequest(),
                true,
            ),
            BrokeredEffectDisposition::Denied,
            BrokeredEffectDisposition::AlreadyConsumed => throw new RuntimeException(
                'The native broker refused a mutating effect without exposing provider credentials.',
            ),
        };
    }

    private function response(
        #[SensitiveParameter] PendingRequest $pendingRequest,
        int $status,
        ?string $contentType,
        #[SensitiveParameter] string $body,
    ): Response {
        $request = $pendingRequest->createPsrRequest();
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
        $psrResponse = new PsrResponse($status, $headers, $body);
        $responseClass = $pendingRequest->getResponseClass();

        return $responseClass::fromPsrResponse($psrResponse, $pendingRequest, $request);
    }

    private function transportFailure(Request $request, bool $timeout = false): RuntimeException
    {
        if ($request instanceof AccountProbeRequest
            || $request instanceof CreateProbeInvoiceRequest
            || $request instanceof CreateTimedProbeInvoiceRequest
            || $request instanceof SearchProbeInvoicesRequest) {
            return $timeout ? ProbeTransportException::timeout() : ProbeTransportException::failure();
        }

        return new RuntimeException('The native broker provider transport failed without exposing credentials.');
    }

    /** @return array{string, string} */
    private function target(#[SensitiveParameter] string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $targets = [
            'primary.s03-native.invalid' => ['invoice_identity', 'primary'],
            'secondary.s03-native.invalid' => ['invoice_identity', 'secondary'],
            'explicit-block.s04-native.invalid' => ['explicit_block', 'explicit_block'],
            'explicit-persist.s04-native.invalid' => ['explicit_persist', 'explicit_persist'],
            'auto-block.s04-native.invalid' => ['auto_block', 'auto_block'],
            'auto-persist.s04-native.invalid' => ['auto_persist', 'auto_persist'],
        ];

        if (! is_string($host) || ! isset($targets[$host])) {
            throw new InvalidArgumentException('The native broker placeholder origin is not allowlisted.');
        }

        return $targets[$host];
    }

    /** @return array{int, int} */
    private function timeouts(#[SensitiveParameter] PendingRequest $pendingRequest): array
    {
        $config = $pendingRequest->config()->all();
        $connect = $config[RequestOptions::CONNECT_TIMEOUT] ?? null;
        $request = $config[RequestOptions::TIMEOUT] ?? null;

        if ((! is_int($connect) && ! is_float($connect))
            || (! is_int($request) && ! is_float($request))) {
            throw new InvalidArgumentException('The native broker request has no bounded Saloon timeouts.');
        }

        $connectMs = (int) round($connect * 1_000);
        $requestMs = (int) round($request * 1_000);

        if ($connectMs < 1 || $requestMs < $connectMs) {
            throw new InvalidArgumentException('The native broker request timeout relation is invalid.');
        }

        return [$connectMs, $requestMs];
    }

    /** @param list<string> $keys */
    private function assertQuery(#[SensitiveParameter] PendingRequest $pendingRequest, array $keys): void
    {
        $this->query($pendingRequest, $keys);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function query(#[SensitiveParameter] PendingRequest $pendingRequest, array $keys): array
    {
        $query = $pendingRequest->query()->all();
        $actualKeys = array_keys($query);
        sort($actualKeys, SORT_STRING);
        sort($keys, SORT_STRING);

        if ($actualKeys !== $keys
            || ($query['api_token'] ?? null) !== self::TokenSentinel
            || (array_key_exists('integration_token', $query) && $query['integration_token'] !== '')) {
            throw new InvalidArgumentException('The native broker adapter rejects unknown query fields and caller credentials.');
        }

        return $query;
    }

    /** @return array{api_token: string, invoice: array<string, mixed>} */
    private function body(#[SensitiveParameter] PendingRequest $pendingRequest): array
    {
        $body = $pendingRequest->body()?->all();

        if (! is_array($body)
            || array_is_list($body)
            || array_keys($body) !== ['api_token', 'invoice']
            || ($body['api_token'] ?? null) !== self::TokenSentinel
            || ! is_array($body['invoice'] ?? null)
            || array_is_list($body['invoice'])) {
            throw new InvalidArgumentException('The native broker adapter accepts one exact token-free invoice body.');
        }

        return ['api_token' => self::TokenSentinel, 'invoice' => $body['invoice']];
    }

    private function oid(#[SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9._-]{4,191}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The native broker invoice OID is invalid.');
        }

        return rawurlencode($value);
    }

    private function page(#[SensitiveParameter] mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('The native broker search page is invalid.');
        }

        return $value;
    }

    private function canonicalDocumentPath(Request $request, string $suffix): string
    {
        $endpoint = $request->resolveEndpoint();

        if (preg_match('/^\/invoices\/[1-9][0-9]{0,18}'.preg_quote($suffix, '/').'$/D', $endpoint) !== 1) {
            throw new InvalidArgumentException('The native broker document endpoint is invalid.');
        }

        return $endpoint;
    }
}
