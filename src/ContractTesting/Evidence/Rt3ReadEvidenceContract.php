<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\Evidence;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class Rt3ReadEvidenceContract
{
    use ValidatesDiagnosticEvidence;

    public const Contract = 'cieplik206.fakturownia.rt3-read-evidence';

    public const FixtureContract = 'cieplik206.fakturownia.rt3-read-fixture';

    public const Version = '1';

    public const Disposition = 'diagnostic_only_not_runtime_authority';

    private const JsonMaximumResponseBytes = 8_388_608;

    /**
     * @var array<string, array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}>
     */
    private const Capabilities = [
        'invoice.read.list' => [
            'endpoint' => '/invoices.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => ['client_id', 'date_from', 'date_to', 'from_invoice_id', 'income', 'include_positions', 'kind', 'kinds', 'number', 'order', 'page', 'per_page', 'period', 'search_date_type', 'warehouse_id'],
            'cases' => ['success', 'provider_error_200', 'unknown_fields', 'pagination_complete', 'exact_oid_complete', 'bounded_retry'],
        ],
        'invoice.read.get' => [
            'endpoint' => '/invoices/{id}.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => [],
            'cases' => ['success', 'provider_error_200', 'bounded_retry'],
        ],
        'client.read.list' => [
            'endpoint' => '/clients.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => ['email', 'external_id', 'name', 'page', 'per_page', 'tax_no'],
            'cases' => ['success', 'provider_error_200', 'unknown_fields', 'pagination_complete', 'bounded_retry'],
        ],
        'client.read.get' => [
            'endpoint' => '/clients/{id}.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => [],
            'cases' => ['success', 'provider_error_200', 'bounded_retry'],
        ],
        'product.read.list' => [
            'endpoint' => '/products.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => ['page', 'per_page'],
            'cases' => ['success', 'provider_error_200', 'unknown_fields', 'pagination_complete', 'bounded_retry'],
        ],
        'product.read.get' => [
            'endpoint' => '/products/{id}.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => [],
            'cases' => ['success', 'provider_error_200', 'bounded_retry'],
        ],
        'payment.read.list' => [
            'endpoint' => '/banking/payments.json',
            'kind' => 'json',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => ['application/json'],
            'query_keys' => ['include', 'page', 'per_page'],
            'cases' => ['success', 'provider_error_200', 'unknown_fields', 'pagination_complete', 'bounded_retry'],
        ],
        'payment.read.get' => [
            'endpoint' => '/banking/payment/{id}.json',
            'kind' => 'unsupported',
            'maximum_response_bytes' => self::JsonMaximumResponseBytes,
            'content_types' => [],
            'query_keys' => [],
            'cases' => ['runtime_unsupported'],
        ],
        'invoice.pdf.stream' => [
            'endpoint' => '/invoices/{id}.pdf',
            'kind' => 'binary',
            'maximum_response_bytes' => 20_971_520,
            'content_types' => ['application/pdf'],
            'query_keys' => [],
            'cases' => ['success', 'redirect_policy', 'corrupt_rejected', 'bounded_retry'],
        ],
        'invoice.attachments.zip.stream' => [
            'endpoint' => '/invoices/{id}/attachments_zip.json',
            'kind' => 'binary',
            'maximum_response_bytes' => 52_428_800,
            'content_types' => ['application/zip', 'application/x-zip-compressed'],
            'query_keys' => [],
            'cases' => ['success', 'redirect_policy', 'corrupt_rejected', 'bounded_retry'],
        ],
        'invoice.ksef.xml.stream' => [
            'endpoint' => '/invoices/{id}/attachment',
            'kind' => 'binary',
            'maximum_response_bytes' => 10_485_760,
            'content_types' => ['application/xml', 'text/xml'],
            'query_keys' => ['kind'],
            'fixed_query_contract' => 'fixed_kind_gov_v1',
            'cases' => ['success', 'ready_302', 'missing_404', 'redirect_policy', 'corrupt_rejected', 'bounded_retry'],
        ],
        'invoice.ksef.upo.stream' => [
            'endpoint' => '/invoices/{id}/attachment',
            'kind' => 'binary',
            'maximum_response_bytes' => 10_485_760,
            'content_types' => ['application/xml', 'text/xml'],
            'query_keys' => ['kind'],
            'fixed_query_contract' => 'fixed_kind_gov_upo_v1',
            'cases' => ['success', 'ready_302', 'missing_404', 'redirect_policy', 'corrupt_rejected', 'bounded_retry'],
        ],
    ];

    private function __construct() {}

    /** @param array<string, mixed> $document */
    public static function assertValid(#[SensitiveParameter] array $document): void
    {
        self::assertCanonicalDocument($document);
        self::assertExactKeys($document, [
            'contract',
            'version',
            'disposition',
            'capability',
            'provider',
            'harness',
            'run',
            'fixture',
            'cases',
            'payload_sha256',
        ], 'RT-3 read evidence');

        if (self::string($document, 'contract', 'RT-3 evidence contract') !== self::Contract
            || self::string($document, 'version', 'RT-3 evidence version') !== self::Version
            || self::string($document, 'disposition', 'RT-3 evidence disposition') !== self::Disposition) {
            throw new InvalidArgumentException('The RT-3 read evidence contract, version or disposition is invalid.');
        }

        $capability = self::string($document, 'capability', 'RT-3 capability');
        $contract = self::Capabilities[$capability] ?? null;

        if (! \is_array($contract)) {
            throw new InvalidArgumentException('The RT-3 read capability is not allowlisted.');
        }

        $provider = self::object($document['provider'] ?? null, 'RT-3 provider');
        self::assertProvider($provider);
        self::assertHarness(self::object($document['harness'] ?? null, 'RT-3 harness'));
        $run = self::object($document['run'] ?? null, 'RT-3 run');
        self::assertRun($run);
        self::assertProviderRunBinding($provider, $run);
        self::assertFixture(self::object($document['fixture'] ?? null, 'RT-3 fixture'), self::FixtureContract);
        self::assertCases(self::list($document['cases'] ?? null, 'RT-3 cases', 16), $contract, $run);
        self::assertPayloadSha256($document);
    }

    /** @param array<string, mixed> $document */
    public static function canonicalSha256(#[SensitiveParameter] array $document): string
    {
        self::assertValid($document);

        return \hash('sha256', CanonicalCodec::encode($document));
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('RT-3 diagnostic evidence guards cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): never
    {
        throw new LogicException('RT-3 diagnostic evidence guards cannot be unserialized.');
    }

    /**
     * @param  list<mixed>  $cases
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     * @param  array<string, mixed>  $run
     */
    private static function assertCases(
        #[SensitiveParameter] array $cases,
        array $contract,
        #[SensitiveParameter] array $run,
    ): void {
        if ($cases === []) {
            throw new InvalidArgumentException('RT-3 read evidence requires cases.');
        }

        $caseIds = [];

        foreach ($cases as $index => $value) {
            $case = self::object($value, 'RT-3 case');
            self::assertExactKeys($case, ['id', 'observed_at', 'request', 'response', 'retry', 'proof'], 'RT-3 case');
            $caseId = self::string($case, 'id', 'RT-3 case ID');

            if (($contract['cases'][$index] ?? null) !== $caseId) {
                throw new InvalidArgumentException('The RT-3 evidence cases must use the exact capability coverage order.');
            }

            $caseIds[$caseId] = true;
            self::assertInstantWithinRun(self::string($case, 'observed_at', 'case observation time'), $run, 'case observation time');
            self::assertRequest($caseId, self::object($case['request'] ?? null, 'RT-3 request'), $contract);
            self::assertCaseResponse(
                $caseId,
                self::object($case['response'] ?? null, 'RT-3 response'),
                self::object($case['retry'] ?? null, 'RT-3 retry'),
                $contract,
            );
            self::assertCaseProof($caseId, self::object($case['proof'] ?? null, 'RT-3 proof'), $contract);
        }

        if (\array_keys($caseIds) !== $contract['cases']) {
            throw new InvalidArgumentException('The RT-3 evidence case set is incomplete or duplicated.');
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     */
    private static function assertRequest(string $caseId, #[SensitiveParameter] array $request, array $contract): void
    {
        self::assertExactKeys($request, [
            'method',
            'endpoint_template',
            'canonical_query_hmac_sha256',
            'canonical_query_keys',
            'query_contract',
            'exact_oid_hmac_sha256',
            'remote_identity_hmac_sha256',
            'remote_snapshot_hmac_sha256',
            'terminal_operation_hmac_sha256',
            'descriptor_sha256',
            'safe_request_hmac_sha256',
            'maximum_response_bytes',
        ], 'RT-3 request');

        if (self::string($request, 'method', 'request method') !== 'GET'
            || self::string($request, 'endpoint_template', 'endpoint template') !== $contract['endpoint']
            || self::integer($request, 'maximum_response_bytes', 'maximum response bytes') !== $contract['maximum_response_bytes']) {
            throw new InvalidArgumentException('The RT-3 request tuple does not match its capability.');
        }

        foreach (['canonical_query_hmac_sha256', 'descriptor_sha256', 'safe_request_hmac_sha256'] as $field) {
            self::assertSha256(self::string($request, $field, $field), $field);
        }

        $queryKeys = self::list($request['canonical_query_keys'] ?? null, 'canonical query keys', 16);
        $queryContract = self::string($request, 'query_contract', 'query contract');
        $exactOidHmac = $request['exact_oid_hmac_sha256'] ?? null;
        $remoteIdentityHmac = $request['remote_identity_hmac_sha256'] ?? null;
        $remoteSnapshotHmac = $request['remote_snapshot_hmac_sha256'] ?? null;
        $terminalOperationHmac = $request['terminal_operation_hmac_sha256'] ?? null;
        $expectedExactOidKeys = ['date_from', 'date_to', 'income', 'include_positions', 'kind', 'oid', 'order', 'page', 'per_page', 'period', 'search_date_type'];

        foreach ($queryKeys as $key) {
            if (! \is_string($key)) {
                throw new InvalidArgumentException('The canonical query key tuple is invalid.');
            }

            self::assertIdentifier($key, 'canonical query key');
        }

        $requiresRemoteIdentity = \str_contains($contract['endpoint'], '{id}');
        $requiresArtifactState = $contract['endpoint'] === '/invoices/{id}.pdf';

        if (($requiresRemoteIdentity && ! \is_string($remoteIdentityHmac))
            || (! $requiresRemoteIdentity && $remoteIdentityHmac !== null)
            || ($requiresArtifactState && (! \is_string($remoteSnapshotHmac) || ! \is_string($terminalOperationHmac)))
            || (! $requiresArtifactState && ($remoteSnapshotHmac !== null || $terminalOperationHmac !== null))) {
            throw new InvalidArgumentException('The RT-3 remote request binding is invalid.');
        }

        foreach ([$remoteIdentityHmac, $remoteSnapshotHmac, $terminalOperationHmac] as $binding) {
            if (\is_string($binding)) {
                self::assertSha256($binding, 'remote request binding HMAC');
            }
        }

        if ($caseId === 'exact_oid_complete') {
            if ($contract['endpoint'] !== '/invoices.json'
                || $queryContract !== 'exact_oid_query_v1'
                || $queryKeys !== $expectedExactOidKeys
                || ! \is_string($exactOidHmac)) {
                throw new InvalidArgumentException('The exact-OID request query contract is invalid.');
            }

            self::assertSha256($exactOidHmac, 'exact-OID HMAC');

            return;
        }

        $expectedContract = $contract['fixed_query_contract']
            ?? ($contract['query_keys'] === [] ? 'no_query_v1' : 'capability_query_v1');

        if ($queryContract !== $expectedContract
            || $queryKeys !== $contract['query_keys']
            || $exactOidHmac !== null) {
            throw new InvalidArgumentException('The RT-3 request query contract is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $retry
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     */
    private static function assertCaseResponse(
        string $caseId,
        #[SensitiveParameter] array $response,
        #[SensitiveParameter] array $retry,
        array $contract,
    ): void {
        self::assertExactKeys($response, [
            'outcome',
            'status_code',
            'content_type',
            'content_length',
            'provider_request_id_class',
            'provider_request_id_hmac_sha256',
            'safe_response_hmac_sha256',
            'body_sha256',
            'redirect_chain',
            'validation',
        ], 'RT-3 response');
        self::assertExactKeys($retry, [
            'attempts',
            'maximum_attempts',
            'base_delay_ms',
            'maximum_delay_ms',
            'maximum_total_delay_ms',
            'retryable_statuses',
            'policy_sha256',
        ], 'RT-3 retry');

        $outcome = self::string($response, 'outcome', 'response outcome');
        $validation = self::string($response, 'validation', 'response validation');
        $statusCode = $response['status_code'] ?? null;
        $contentType = $response['content_type'] ?? null;
        $contentLength = $response['content_length'] ?? null;
        $bodySha256 = $response['body_sha256'] ?? null;
        $attempts = self::list($retry['attempts'] ?? null, 'RT-3 retry attempts', 4);

        if (! \in_array($outcome, ['response', 'transport_failure'], true)) {
            throw new InvalidArgumentException('The RT-3 response outcome or retry attempt is invalid.');
        }

        self::assertSha256(self::string($response, 'safe_response_hmac_sha256', 'safe response HMAC'), 'safe response HMAC');
        self::assertProviderRequestId($response);
        self::assertRetryPolicy($retry);
        self::assertRetryAttempts($attempts, $outcome, $statusCode, $caseId === 'bounded_retry');
        $redirects = self::list($response['redirect_chain'] ?? null, 'redirect chain', 3);
        self::assertRedirects($redirects);

        if ($outcome === 'transport_failure') {
            if ($statusCode !== null || $contentType !== null || $contentLength !== null || $bodySha256 !== null || $redirects !== []) {
                throw new InvalidArgumentException('A transport failure cannot contain a provider response.');
            }
        } else {
            if (! \is_int($statusCode) || $statusCode < 100 || $statusCode > 599
                || ($contentType !== null && ! \is_string($contentType))
                || ($contentLength !== null && (! \is_int($contentLength) || $contentLength < 0 || $contentLength > $contract['maximum_response_bytes']))) {
                throw new InvalidArgumentException('The RT-3 provider response metadata is invalid.');
            }

            if (\is_string($contentType)
                && \preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\z/D', $contentType) !== 1) {
                throw new InvalidArgumentException('The RT-3 provider content type is invalid.');
            }
        }

        self::assertCaseSemantics($caseId, $outcome, $validation, $statusCode, $contentType, $contentLength, $bodySha256, $redirects, \count($attempts), $contract);
    }

    /**
     * @param  list<mixed>  $redirects
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     */
    private static function assertCaseSemantics(
        string $caseId,
        #[SensitiveParameter] string $outcome,
        #[SensitiveParameter] string $validation,
        #[SensitiveParameter] mixed $statusCode,
        #[SensitiveParameter] mixed $contentType,
        #[SensitiveParameter] mixed $contentLength,
        #[SensitiveParameter] mixed $bodySha256,
        #[SensitiveParameter] array $redirects,
        int $attemptCount,
        array $contract,
    ): void {
        if ($caseId === 'runtime_unsupported') {
            if ($contract['kind'] !== 'unsupported'
                || $outcome !== 'transport_failure'
                || $validation !== 'runtime_unsupported'
                || $attemptCount !== 1) {
                throw new InvalidArgumentException('The unsupported RT-3 capability evidence is invalid.');
            }

            return;
        }

        if ($caseId === 'bounded_retry') {
            $finalValidation = $contract['kind'] === 'binary' ? 'binary_valid' : 'typed_success';

            if ($outcome !== 'response'
                || $statusCode !== 200
                || $validation !== $finalValidation
                || $attemptCount < 2) {
                throw new InvalidArgumentException('The RT-3 bounded retry evidence is invalid.');
            }

            if (! \is_string($contentType)
                || ! \in_array($contentType, $contract['content_types'], true)
                || ! \is_int($contentLength)
                || $contentLength < 1
                || $redirects !== []) {
                throw new InvalidArgumentException('The RT-3 bounded retry final response is invalid.');
            }

            self::assertBodyDigest($bodySha256, $contract['kind'] === 'binary');

            return;
        }

        if ($outcome !== 'response' || ! \is_int($statusCode)) {
            throw new InvalidArgumentException('The RT-3 evidence case requires a provider response.');
        }

        if (\in_array($caseId, ['success', 'unknown_fields', 'pagination_complete', 'exact_oid_complete'], true)) {
            $expectedValidation = match ($caseId) {
                'unknown_fields' => 'typed_success_unknown_fields',
                'pagination_complete' => 'pagination_complete',
                'exact_oid_complete' => 'exact_oid_complete',
                default => $contract['kind'] === 'json' ? 'typed_success' : 'binary_valid',
            };

            if ($statusCode !== 200
                || $validation !== $expectedValidation
                || ! \is_string($contentType)
                || ! \in_array($contentType, $contract['content_types'], true)
                || ! \is_int($contentLength)
                || $contentLength < 1
                || $redirects !== []) {
                throw new InvalidArgumentException('The RT-3 success evidence is invalid.');
            }

            self::assertBodyDigest($bodySha256, $contract['kind'] === 'binary');

            return;
        }

        if ($caseId === 'provider_error_200') {
            if ($contract['kind'] !== 'json'
                || $statusCode !== 200
                || $validation !== 'provider_error_envelope_detected'
                || $contentType !== 'application/json'
                || ! \is_int($contentLength)
                || $contentLength < 1
                || $bodySha256 !== null
                || $redirects !== []) {
                throw new InvalidArgumentException('The RT-3 provider error-envelope evidence is invalid.');
            }

            return;
        }

        if (\in_array($caseId, ['redirect_policy', 'ready_302'], true)) {
            if ($contract['kind'] !== 'binary'
                || $statusCode !== 200
                || $validation !== 'binary_valid'
                || ! \is_string($contentType)
                || ! \in_array($contentType, $contract['content_types'], true)
                || ! \is_int($contentLength)
                || $contentLength < 1
                || $redirects === []) {
                throw new InvalidArgumentException('The RT-3 redirect evidence is invalid.');
            }

            if ($caseId === 'ready_302'
                && ! \in_array(302, \array_column($redirects, 'status_code'), true)) {
                throw new InvalidArgumentException('The RT-3 ready redirect must observe HTTP 302.');
            }

            if (! \in_array(true, \array_column($redirects, 'cross_host'), true)) {
                throw new InvalidArgumentException('The RT-3 redirect proof must exercise cross-host credential stripping.');
            }

            self::assertBodyDigest($bodySha256, true);

            return;
        }

        if ($caseId === 'missing_404') {
            if ($statusCode !== 404 || $validation !== 'missing_404' || $bodySha256 !== null || $redirects !== []) {
                throw new InvalidArgumentException('The RT-3 missing-artifact evidence is invalid.');
            }

            return;
        }

        if ($caseId === 'corrupt_rejected') {
            if ($contract['kind'] !== 'binary'
                || $statusCode !== 200
                || $validation !== 'corrupt_rejected'
                || ! \is_string($contentType)
                || ! \in_array($contentType, $contract['content_types'], true)
                || ! \is_int($contentLength)
                || $contentLength < 1
                || $redirects !== []) {
                throw new InvalidArgumentException('The RT-3 corrupt-artifact rejection evidence is invalid.');
            }

            self::assertBodyDigest($bodySha256, true);

            return;
        }

        throw new InvalidArgumentException('The RT-3 evidence case ID is unsupported.');
    }

    /** @param array<string, mixed> $response */
    private static function assertProviderRequestId(#[SensitiveParameter] array $response): void
    {
        $class = self::string($response, 'provider_request_id_class', 'provider request ID class');
        $hmac = $response['provider_request_id_hmac_sha256'] ?? null;

        if ($class === 'absent' && $hmac === null) {
            return;
        }

        if ($class !== 'opaque_bounded' || ! \is_string($hmac)) {
            throw new InvalidArgumentException('The provider request ID evidence is invalid.');
        }

        self::assertSha256($hmac, 'provider request ID HMAC');
    }

    /**
     * @param  list<mixed>  $attempts
     */
    private static function assertRetryAttempts(
        #[SensitiveParameter] array $attempts,
        #[SensitiveParameter] string $finalOutcome,
        #[SensitiveParameter] mixed $finalStatusCode,
        bool $retried,
    ): void {
        if ($attempts === [] || ($retried && \count($attempts) < 2) || (! $retried && \count($attempts) !== 1)) {
            throw new InvalidArgumentException('The RT-3 retry sequence cardinality is invalid.');
        }

        $totalDelay = 0;

        foreach ($attempts as $index => $value) {
            $attempt = self::object($value, 'RT-3 retry attempt');
            self::assertExactKeys($attempt, [
                'sequence',
                'transport_outcome',
                'status_code',
                'parsed_retry_after_ms',
                'scheduled_delay_ms',
            ], 'RT-3 retry attempt');
            $sequence = self::integer($attempt, 'sequence', 'retry sequence');
            $outcome = self::string($attempt, 'transport_outcome', 'retry transport outcome');
            $statusCode = $attempt['status_code'] ?? null;
            $parsedRetryAfter = $attempt['parsed_retry_after_ms'] ?? null;
            $scheduledDelay = $attempt['scheduled_delay_ms'] ?? null;

            if ($sequence !== $index + 1
                || ! \in_array($outcome, ['response', 'transport_failure'], true)
                || ($outcome === 'transport_failure' && $statusCode !== null)
                || ($outcome === 'response' && (! \is_int($statusCode) || $statusCode < 100 || $statusCode > 599))) {
                throw new InvalidArgumentException('The RT-3 retry sequence outcome is invalid.');
            }

            foreach ([$parsedRetryAfter, $scheduledDelay] as $delay) {
                if ($delay !== null && (! \is_int($delay) || $delay < 0 || $delay > 120_000)) {
                    throw new InvalidArgumentException('The RT-3 retry delay evidence is invalid.');
                }
            }

            if (\is_int($parsedRetryAfter)
                && (! \is_int($scheduledDelay) || $scheduledDelay < $parsedRetryAfter)) {
                throw new InvalidArgumentException('The scheduled retry delay cannot precede Retry-After.');
            }

            $isFinal = $index === \count($attempts) - 1;

            if ($isFinal) {
                if ($outcome !== $finalOutcome
                    || $statusCode !== $finalStatusCode
                    || $parsedRetryAfter !== null
                    || $scheduledDelay !== null) {
                    throw new InvalidArgumentException('The RT-3 final retry attempt does not match the response.');
                }

                continue;
            }

            $isRetryable = $outcome === 'transport_failure'
                || \in_array($statusCode, [408, 429, 500, 502, 503, 504], true);

            $exponentialMaximum = \min(8_000, 250 * (2 ** \max(0, $sequence - 1)));
            $allowedMaximum = \max($exponentialMaximum, \is_int($parsedRetryAfter) ? $parsedRetryAfter : 0);

            if (! $isRetryable
                || ! \is_int($scheduledDelay)
                || ($outcome === 'transport_failure' && $parsedRetryAfter !== null)
                || $scheduledDelay > $allowedMaximum
                || $scheduledDelay > 8_000) {
                throw new InvalidArgumentException('The RT-3 retry delay evidence is invalid.');
            }

            $totalDelay += $scheduledDelay;

            if ($totalDelay > 30_000) {
                throw new InvalidArgumentException('The RT-3 retry sequence exceeds its total delay budget.');
            }
        }
    }

    /** @param array<string, mixed> $retry */
    private static function assertRetryPolicy(#[SensitiveParameter] array $retry): void
    {
        $policy = [
            'contract' => 'cieplik206.fakturownia.rt3-read-retry-policy',
            'version' => self::Version,
            'maximum_attempts' => 4,
            'base_delay_ms' => 250,
            'maximum_delay_ms' => 8_000,
            'maximum_total_delay_ms' => 30_000,
            'retryable_statuses' => [408, 429, 500, 502, 503, 504],
        ];

        foreach (['maximum_attempts', 'base_delay_ms', 'maximum_delay_ms', 'maximum_total_delay_ms'] as $field) {
            if (self::integer($retry, $field, $field) !== $policy[$field]) {
                throw new InvalidArgumentException('The RT-3 retry policy does not match the reviewed runtime policy.');
            }
        }

        if (self::list($retry['retryable_statuses'] ?? null, 'retryable statuses', 6) !== $policy['retryable_statuses']) {
            throw new InvalidArgumentException('The RT-3 retryable status set is invalid.');
        }

        $expected = \hash('sha256', CanonicalCodec::encode($policy));
        $actual = self::string($retry, 'policy_sha256', 'retry policy SHA-256');
        self::assertSha256($actual, 'retry policy SHA-256');

        if (! \hash_equals($expected, $actual)) {
            throw new InvalidArgumentException('The RT-3 retry policy SHA-256 is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $proof
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     */
    private static function assertCaseProof(string $caseId, #[SensitiveParameter] array $proof, array $contract): void
    {
        if ($caseId === 'runtime_unsupported') {
            self::assertExactKeys($proof, ['dispatch_attempted'], 'unsupported case proof');

            if (self::boolean($proof, 'dispatch_attempted', 'dispatch attempted')) {
                throw new InvalidArgumentException('An unsupported RT-3 capability cannot dispatch.');
            }

            return;
        }

        if ($caseId === 'success') {
            if ($contract['kind'] === 'json') {
                self::assertAllTrue($proof, ['typed_mapping'], 'success case proof');

                return;
            }

            $fields = match ($contract['content_types']) {
                ['application/pdf'] => ['pdf_magic_valid', 'pdf_trailer_valid', 'stream_bounds_enforced'],
                ['application/zip', 'application/x-zip-compressed'] => ['zip_magic_valid', 'zip_central_directory_valid', 'stream_bounds_enforced'],
                default => ['xml_well_formed', 'xml_nonet', 'stream_bounds_enforced'],
            };
            self::assertAllTrue($proof, $fields, 'binary success case proof');

            return;
        }

        if ($caseId === 'provider_error_200') {
            self::assertAllTrue($proof, ['error_envelope_detected', 'exception_sanitized'], 'provider error case proof');

            return;
        }

        if ($caseId === 'unknown_fields') {
            self::assertAllTrue($proof, ['unknown_fields_tolerated', 'open_enums_preserved'], 'unknown-fields case proof');

            return;
        }

        if ($caseId === 'pagination_complete') {
            self::assertExactKeys($proof, [
                'pages_scanned',
                'per_page',
                'items_observed',
                'terminal_page_observed',
                'stable_ordering',
                'duplicate_page_guard',
                'remote_id_deduplication',
            ], 'pagination case proof');
            $pagesScanned = self::integer($proof, 'pages_scanned', 'pagination page count');
            $perPage = self::integer($proof, 'per_page', 'pagination page size');
            $itemsObserved = self::integer($proof, 'items_observed', 'pagination item count');

            if ($contract['query_keys'] === []
                || $pagesScanned < 1
                || $pagesScanned > 100
                || $perPage < 1
                || $perPage > 100
                || $itemsObserved < 0) {
                throw new InvalidArgumentException('The RT-3 pagination completeness proof is invalid.');
            }

            $minimumItemsObserved = ($pagesScanned - 1) * $perPage;
            $maximumItemsObserved = ($pagesScanned * $perPage) - 1;

            if ($itemsObserved < $minimumItemsObserved
                || $itemsObserved > $maximumItemsObserved
                || ! self::boolean($proof, 'terminal_page_observed', 'terminal page observed')
                || ! self::boolean($proof, 'stable_ordering', 'stable ordering')
                || ! self::boolean($proof, 'duplicate_page_guard', 'duplicate page guard')
                || ! self::boolean($proof, 'remote_id_deduplication', 'remote ID deduplication')) {
                throw new InvalidArgumentException('The RT-3 pagination completeness proof is invalid.');
            }

            return;
        }

        if ($caseId === 'exact_oid_complete') {
            self::assertExactKeys($proof, [
                'pages_scanned',
                'next_page_exhausted',
                'duplicate_page_guard',
                'stable_ordering',
                'match_count',
                'strict_decimal_scalar_provenance',
            ], 'exact-OID case proof');
            $pagesScanned = self::integer($proof, 'pages_scanned', 'exact-OID page count');
            $matchCount = self::integer($proof, 'match_count', 'exact-OID match count');

            if ($contract['endpoint'] !== '/invoices.json'
                || $pagesScanned < 1
                || $pagesScanned > 100
                || ! \in_array($matchCount, [0, 1], true)
                || ! self::boolean($proof, 'next_page_exhausted', 'next-page exhaustion')
                || ! self::boolean($proof, 'duplicate_page_guard', 'duplicate-page guard')
                || ! self::boolean($proof, 'stable_ordering', 'stable ordering')
                || ! self::boolean($proof, 'strict_decimal_scalar_provenance', 'decimal scalar provenance')) {
                throw new InvalidArgumentException('The exact-OID completeness proof is invalid.');
            }

            return;
        }

        if ($caseId === 'bounded_retry') {
            $fields = ['retry_after_bounded', 'attempt_budget_enforced'];

            if ($contract['kind'] === 'json') {
                $fields[] = 'typed_mapping';
            } else {
                $fields = [...$fields, ...self::binaryValidationProofFields($contract)];
            }

            self::assertAllTrue($proof, $fields, 'bounded retry proof');

            return;
        }

        if (\in_array($caseId, ['redirect_policy', 'ready_302'], true)) {
            self::assertAllTrue($proof, [
                'host_policy_enforced',
                'cross_host_credentials_stripped',
                ...self::binaryValidationProofFields($contract),
            ], 'redirect case proof');

            return;
        }

        if ($caseId === 'missing_404') {
            self::assertAllTrue($proof, ['typed_missing'], 'missing case proof');

            return;
        }

        if ($caseId === 'corrupt_rejected') {
            self::assertAllTrue($proof, ['magic_rejected', 'trailer_or_xml_validation_rejected'], 'corrupt case proof');

            return;
        }

        throw new InvalidArgumentException('The RT-3 case proof is unsupported.');
    }

    /**
     * @param  array<string, mixed>  $proof
     * @param  list<string>  $fields
     */
    private static function assertAllTrue(#[SensitiveParameter] array $proof, array $fields, string $label): void
    {
        self::assertExactKeys($proof, $fields, $label);

        foreach ($fields as $field) {
            if (! self::boolean($proof, $field, $field)) {
                throw new InvalidArgumentException("The RT-3 {$field} proof must pass.");
            }
        }
    }

    /**
     * @param  array{endpoint: string, kind: string, maximum_response_bytes: int, content_types: list<string>, query_keys: list<string>, cases: list<string>, fixed_query_contract?: string}  $contract
     * @return list<string>
     */
    private static function binaryValidationProofFields(array $contract): array
    {
        return match ($contract['content_types']) {
            ['application/pdf'] => ['pdf_magic_valid', 'pdf_trailer_valid', 'stream_bounds_enforced'],
            ['application/zip', 'application/x-zip-compressed'] => ['zip_magic_valid', 'zip_central_directory_valid', 'stream_bounds_enforced'],
            default => ['xml_well_formed', 'xml_nonet', 'stream_bounds_enforced'],
        };
    }

    /** @param list<mixed> $redirects */
    private static function assertRedirects(#[SensitiveParameter] array $redirects): void
    {
        $credentialsDetached = false;

        foreach ($redirects as $value) {
            $redirect = self::object($value, 'redirect');
            self::assertExactKeys($redirect, ['status_code', 'host_policy_hmac_sha256', 'cross_host', 'credentials_stripped'], 'redirect');
            $statusCode = self::integer($redirect, 'status_code', 'redirect status');
            $crossHost = self::boolean($redirect, 'cross_host', 'cross-host flag');
            $credentialsStripped = self::boolean($redirect, 'credentials_stripped', 'credentials-stripped flag');

            $mustStripCredentials = $credentialsDetached || $crossHost;

            if (! \in_array($statusCode, [301, 302, 303, 307, 308], true)
                || $credentialsStripped !== $mustStripCredentials) {
                throw new InvalidArgumentException('The RT-3 redirect proof is invalid.');
            }

            $credentialsDetached = $mustStripCredentials;

            self::assertSha256(self::string($redirect, 'host_policy_hmac_sha256', 'redirect host policy HMAC'), 'redirect host policy HMAC');
        }
    }

    private static function assertBodyDigest(#[SensitiveParameter] mixed $value, bool $required): void
    {
        if (! $required) {
            if ($value !== null) {
                throw new InvalidArgumentException('JSON evidence cannot publish a raw body SHA-256.');
            }

            return;
        }

        if (! \is_string($value)) {
            throw new InvalidArgumentException('The RT-3 body SHA-256 evidence is invalid.');
        }

        self::assertSha256($value, 'body SHA-256');
    }
}
