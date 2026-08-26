<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\Evidence\Rt3ReadEvidenceContract;
use Cieplik206\Fakturownia\ContractTesting\Evidence\Rt6ArtifactEvidenceContract;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;

function fakturowniaDiagnosticSha(string $label): string
{
    return hash('sha256', $label);
}

/** @return array<string, mixed> */
function fakturowniaDiagnosticHarness(): array
{
    return [
        'repository_commit' => str_repeat('1', 40),
        'code_sha256' => fakturowniaDiagnosticSha('code'),
        'launch_manifest_sha256' => fakturowniaDiagnosticSha('launch'),
        'dependency_lock_sha256' => fakturowniaDiagnosticSha('lock'),
        'php_runtime_sha256' => fakturowniaDiagnosticSha('php-runtime'),
        'saloon_package_version' => '4.10.0',
        'saloon_package_tree_sha256' => fakturowniaDiagnosticSha('saloon-tree'),
        'saloon_runtime_sha256' => fakturowniaDiagnosticSha('saloon-runtime'),
    ];
}

/** @return array<string, mixed> */
function fakturowniaDiagnosticProvider(): array
{
    return [
        'profile' => 'demo_pl',
        'connection_scope_hmac_sha256' => fakturowniaDiagnosticSha('connection-scope'),
        'hmac_policy' => [
            'contract' => 'cieplik206.fakturownia.diagnostic-hmac-policy',
            'version' => '1',
            'key_id_sha256' => fakturowniaDiagnosticSha('diagnostic-hmac-key-id'),
            'framing' => 'canonical_length_prefixed_field_domains_v1',
        ],
    ];
}

/** @return array<string, mixed> */
function fakturowniaDiagnosticRun(): array
{
    return [
        'run_id' => str_repeat('a', 32),
        'started_at' => '2026-08-26T10:00:00.000000Z',
        'finished_at' => '2026-08-26T10:05:00.000000Z',
        'environment' => 'demo_pl',
    ];
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function fakturowniaDiagnosticPayloadHash(array $document): array
{
    unset($document['payload_sha256']);
    $document['payload_sha256'] = hash('sha256', CanonicalCodec::encode($document));

    return $document;
}

/** @return array<string, mixed> */
function fakturowniaRt3Request(string $endpoint, int $maximumResponseBytes, ?string $fixedQueryContract = null): array
{
    $queryKeys = match ($endpoint) {
        '/invoices.json' => ['client_id', 'date_from', 'date_to', 'from_invoice_id', 'income', 'include_positions', 'kind', 'kinds', 'number', 'order', 'page', 'per_page', 'period', 'search_date_type', 'warehouse_id'],
        '/clients.json' => ['email', 'external_id', 'name', 'page', 'per_page', 'tax_no'],
        '/products.json' => ['page', 'per_page'],
        '/banking/payments.json' => ['include', 'page', 'per_page'],
        '/invoices/{id}/attachment' => ['kind'],
        default => [],
    };
    $remoteIdentityHmac = str_contains($endpoint, '{id}')
        ? fakturowniaDiagnosticSha('remote-invoice')
        : null;
    $requiresArtifactState = $endpoint === '/invoices/{id}.pdf';

    return [
        'method' => 'GET',
        'endpoint_template' => $endpoint,
        'canonical_query_hmac_sha256' => fakturowniaDiagnosticSha('query'),
        'canonical_query_keys' => $queryKeys,
        'query_contract' => $fixedQueryContract ?? ($queryKeys === [] ? 'no_query_v1' : 'capability_query_v1'),
        'exact_oid_hmac_sha256' => null,
        'remote_identity_hmac_sha256' => $remoteIdentityHmac,
        'remote_snapshot_hmac_sha256' => $requiresArtifactState
            ? fakturowniaDiagnosticSha('remote-snapshot')
            : null,
        'terminal_operation_hmac_sha256' => $requiresArtifactState
            ? fakturowniaDiagnosticSha('ksef-operation')
            : null,
        'descriptor_sha256' => fakturowniaDiagnosticSha('descriptor'),
        'safe_request_hmac_sha256' => fakturowniaDiagnosticSha('request'),
        'maximum_response_bytes' => $maximumResponseBytes,
    ];
}

/**
 * @param  list<array<string, mixed>>  $attempts
 * @return array<string, mixed>
 */
function fakturowniaRt3RetryPolicy(array $attempts): array
{
    $policy = [
        'contract' => 'cieplik206.fakturownia.rt3-read-retry-policy',
        'version' => Rt3ReadEvidenceContract::Version,
        'maximum_attempts' => 4,
        'base_delay_ms' => 250,
        'maximum_delay_ms' => 8_000,
        'maximum_total_delay_ms' => 30_000,
        'retryable_statuses' => [408, 429, 500, 502, 503, 504],
    ];

    return [
        'attempts' => $attempts,
        'maximum_attempts' => $policy['maximum_attempts'],
        'base_delay_ms' => $policy['base_delay_ms'],
        'maximum_delay_ms' => $policy['maximum_delay_ms'],
        'maximum_total_delay_ms' => $policy['maximum_total_delay_ms'],
        'retryable_statuses' => $policy['retryable_statuses'],
        'policy_sha256' => hash('sha256', CanonicalCodec::encode($policy)),
    ];
}

/**
 * @param  list<array<string, mixed>>  $redirects
 * @return array<string, mixed>
 */
function fakturowniaRt3Response(
    string $label,
    string $validation,
    string $outcome = 'response',
    ?int $statusCode = 200,
    ?string $contentType = 'application/json',
    ?int $contentLength = 128,
    ?string $bodySha256 = null,
    array $redirects = [],
): array {
    return [
        'outcome' => $outcome,
        'status_code' => $statusCode,
        'content_type' => $contentType,
        'content_length' => $contentLength,
        'provider_request_id_class' => 'absent',
        'provider_request_id_hmac_sha256' => null,
        'safe_response_hmac_sha256' => fakturowniaDiagnosticSha('response-'.$label),
        'body_sha256' => $bodySha256,
        'redirect_chain' => $redirects,
        'validation' => $validation,
    ];
}

/** @return array<string, mixed> */
function fakturowniaRt3SingleAttempt(string $outcome = 'response', ?int $statusCode = 200): array
{
    return fakturowniaRt3RetryPolicy([[
        'sequence' => 1,
        'transport_outcome' => $outcome,
        'status_code' => $statusCode,
        'parsed_retry_after_ms' => null,
        'scheduled_delay_ms' => null,
    ]]);
}

/** @return array<string, mixed> */
function fakturowniaRt3BoundedRetry(): array
{
    return fakturowniaRt3RetryPolicy([
        [
            'sequence' => 1,
            'transport_outcome' => 'response',
            'status_code' => 429,
            'parsed_retry_after_ms' => 1_000,
            'scheduled_delay_ms' => 1_000,
        ],
        [
            'sequence' => 2,
            'transport_outcome' => 'response',
            'status_code' => 200,
            'parsed_retry_after_ms' => null,
            'scheduled_delay_ms' => null,
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $response
 * @param  array<string, mixed>  $retry
 * @param  array<string, mixed>  $proof
 * @return array<string, mixed>
 */
function fakturowniaRt3Case(
    string $id,
    array $response,
    array $retry,
    array $proof,
    string $endpoint,
    int $maximumResponseBytes,
    ?string $fixedQueryContract = null,
): array {
    $request = fakturowniaRt3Request($endpoint, $maximumResponseBytes, $fixedQueryContract);

    if ($id === 'exact_oid_complete') {
        $request['canonical_query_hmac_sha256'] = fakturowniaDiagnosticSha('exact-oid-query');
        $request['canonical_query_keys'] = ['date_from', 'date_to', 'income', 'include_positions', 'kind', 'oid', 'order', 'page', 'per_page', 'period', 'search_date_type'];
        $request['query_contract'] = 'exact_oid_query_v1';
        $request['exact_oid_hmac_sha256'] = fakturowniaDiagnosticSha('exact-oid');
        $request['descriptor_sha256'] = fakturowniaDiagnosticSha('exact-oid-descriptor');
    }

    return [
        'id' => $id,
        'observed_at' => '2026-08-26T10:02:00.000000Z',
        'request' => $request,
        'response' => $response,
        'retry' => $retry,
        'proof' => $proof,
    ];
}

/** @return array<string, mixed> */
function fakturowniaRt3InvoiceListEvidence(): array
{
    $endpoint = '/invoices.json';
    $maximumResponseBytes = 8_388_608;

    return fakturowniaDiagnosticPayloadHash([
        'contract' => Rt3ReadEvidenceContract::Contract,
        'version' => Rt3ReadEvidenceContract::Version,
        'disposition' => Rt3ReadEvidenceContract::Disposition,
        'capability' => 'invoice.read.list',
        'provider' => fakturowniaDiagnosticProvider(),
        'harness' => fakturowniaDiagnosticHarness(),
        'run' => fakturowniaDiagnosticRun(),
        'fixture' => [
            'contract' => Rt3ReadEvidenceContract::FixtureContract,
            'version' => Rt3ReadEvidenceContract::Version,
            'sha256' => fakturowniaDiagnosticSha('rt3-fixture'),
            'bytes' => 4_096,
        ],
        'cases' => [
            fakturowniaRt3Case(
                'success',
                fakturowniaRt3Response('success', 'typed_success'),
                fakturowniaRt3SingleAttempt(),
                ['typed_mapping' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'provider_error_200',
                fakturowniaRt3Response('error', 'provider_error_envelope_detected'),
                fakturowniaRt3SingleAttempt(),
                ['error_envelope_detected' => true, 'exception_sanitized' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'unknown_fields',
                fakturowniaRt3Response('unknown', 'typed_success_unknown_fields'),
                fakturowniaRt3SingleAttempt(),
                ['unknown_fields_tolerated' => true, 'open_enums_preserved' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'pagination_complete',
                fakturowniaRt3Response('pagination', 'pagination_complete'),
                fakturowniaRt3SingleAttempt(),
                [
                    'pages_scanned' => 3,
                    'per_page' => 25,
                    'items_observed' => 74,
                    'terminal_page_observed' => true,
                    'stable_ordering' => true,
                    'duplicate_page_guard' => true,
                    'remote_id_deduplication' => true,
                ],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'exact_oid_complete',
                fakturowniaRt3Response('oid', 'exact_oid_complete'),
                fakturowniaRt3SingleAttempt(),
                [
                    'pages_scanned' => 3,
                    'next_page_exhausted' => true,
                    'duplicate_page_guard' => true,
                    'stable_ordering' => true,
                    'match_count' => 1,
                    'strict_decimal_scalar_provenance' => true,
                ],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'bounded_retry',
                fakturowniaRt3Response('retry', 'typed_success'),
                fakturowniaRt3BoundedRetry(),
                ['retry_after_bounded' => true, 'attempt_budget_enforced' => true, 'typed_mapping' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
        ],
    ]);
}

/** @return array<string, mixed> */
function fakturowniaRt3PdfEvidence(): array
{
    $endpoint = '/invoices/{id}.pdf';
    $maximumResponseBytes = 20_971_520;
    $bodySha256 = fakturowniaDiagnosticSha('pdf-body');
    $redirect = [[
        'status_code' => 302,
        'host_policy_hmac_sha256' => fakturowniaDiagnosticSha('redirect-policy'),
        'cross_host' => true,
        'credentials_stripped' => true,
    ]];

    return fakturowniaDiagnosticPayloadHash([
        'contract' => Rt3ReadEvidenceContract::Contract,
        'version' => Rt3ReadEvidenceContract::Version,
        'disposition' => Rt3ReadEvidenceContract::Disposition,
        'capability' => 'invoice.pdf.stream',
        'provider' => fakturowniaDiagnosticProvider(),
        'harness' => fakturowniaDiagnosticHarness(),
        'run' => fakturowniaDiagnosticRun(),
        'fixture' => [
            'contract' => Rt3ReadEvidenceContract::FixtureContract,
            'version' => Rt3ReadEvidenceContract::Version,
            'sha256' => fakturowniaDiagnosticSha('rt3-pdf-fixture'),
            'bytes' => 8_192,
        ],
        'cases' => [
            fakturowniaRt3Case(
                'success',
                fakturowniaRt3Response('pdf-success', 'binary_valid', contentType: 'application/pdf', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3SingleAttempt(),
                ['pdf_magic_valid' => true, 'pdf_trailer_valid' => true, 'stream_bounds_enforced' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'redirect_policy',
                fakturowniaRt3Response('pdf-redirect', 'binary_valid', contentType: 'application/pdf', contentLength: 2_048, bodySha256: $bodySha256, redirects: $redirect),
                fakturowniaRt3SingleAttempt(),
                [
                    'host_policy_enforced' => true,
                    'cross_host_credentials_stripped' => true,
                    'pdf_magic_valid' => true,
                    'pdf_trailer_valid' => true,
                    'stream_bounds_enforced' => true,
                ],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'corrupt_rejected',
                fakturowniaRt3Response('pdf-corrupt', 'corrupt_rejected', contentType: 'application/pdf', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3SingleAttempt(),
                ['magic_rejected' => true, 'trailer_or_xml_validation_rejected' => true],
                $endpoint,
                $maximumResponseBytes,
            ),
            fakturowniaRt3Case(
                'bounded_retry',
                fakturowniaRt3Response('pdf-retry', 'binary_valid', contentType: 'application/pdf', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3BoundedRetry(),
                [
                    'retry_after_bounded' => true,
                    'attempt_budget_enforced' => true,
                    'pdf_magic_valid' => true,
                    'pdf_trailer_valid' => true,
                    'stream_bounds_enforced' => true,
                ],
                $endpoint,
                $maximumResponseBytes,
            ),
        ],
    ]);
}

/** @return array<string, mixed> */
function fakturowniaRt3KsefXmlEvidence(bool $upo = false): array
{
    $endpoint = '/invoices/{id}/attachment';
    $capability = $upo ? 'invoice.ksef.upo.stream' : 'invoice.ksef.xml.stream';
    $queryContract = $upo ? 'fixed_kind_gov_upo_v1' : 'fixed_kind_gov_v1';
    $maximumResponseBytes = 10_485_760;
    $bodySha256 = fakturowniaDiagnosticSha($upo ? 'upo-body' : 'xml-body');
    $redirect = [[
        'status_code' => 302,
        'host_policy_hmac_sha256' => fakturowniaDiagnosticSha('ksef-redirect-policy'),
        'cross_host' => true,
        'credentials_stripped' => true,
    ]];
    $validProof = [
        'xml_well_formed' => true,
        'xml_nonet' => true,
        'stream_bounds_enforced' => true,
    ];
    $redirectProof = [
        'host_policy_enforced' => true,
        'cross_host_credentials_stripped' => true,
        ...$validProof,
    ];

    return fakturowniaDiagnosticPayloadHash([
        'contract' => Rt3ReadEvidenceContract::Contract,
        'version' => Rt3ReadEvidenceContract::Version,
        'disposition' => Rt3ReadEvidenceContract::Disposition,
        'capability' => $capability,
        'provider' => fakturowniaDiagnosticProvider(),
        'harness' => fakturowniaDiagnosticHarness(),
        'run' => fakturowniaDiagnosticRun(),
        'fixture' => [
            'contract' => Rt3ReadEvidenceContract::FixtureContract,
            'version' => Rt3ReadEvidenceContract::Version,
            'sha256' => fakturowniaDiagnosticSha($upo ? 'rt3-upo-fixture' : 'rt3-xml-fixture'),
            'bytes' => 8_192,
        ],
        'cases' => [
            fakturowniaRt3Case(
                'success',
                fakturowniaRt3Response('ksef-success', 'binary_valid', contentType: 'application/xml', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3SingleAttempt(),
                $validProof,
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
            fakturowniaRt3Case(
                'ready_302',
                fakturowniaRt3Response('ksef-ready', 'binary_valid', contentType: 'application/xml', contentLength: 2_048, bodySha256: $bodySha256, redirects: $redirect),
                fakturowniaRt3SingleAttempt(),
                $redirectProof,
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
            fakturowniaRt3Case(
                'missing_404',
                fakturowniaRt3Response('ksef-missing', 'missing_404', statusCode: 404, contentType: null, contentLength: null),
                fakturowniaRt3SingleAttempt(statusCode: 404),
                ['typed_missing' => true],
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
            fakturowniaRt3Case(
                'redirect_policy',
                fakturowniaRt3Response('ksef-redirect', 'binary_valid', contentType: 'application/xml', contentLength: 2_048, bodySha256: $bodySha256, redirects: $redirect),
                fakturowniaRt3SingleAttempt(),
                $redirectProof,
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
            fakturowniaRt3Case(
                'corrupt_rejected',
                fakturowniaRt3Response('ksef-corrupt', 'corrupt_rejected', contentType: 'application/xml', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3SingleAttempt(),
                ['magic_rejected' => true, 'trailer_or_xml_validation_rejected' => true],
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
            fakturowniaRt3Case(
                'bounded_retry',
                fakturowniaRt3Response('ksef-retry', 'binary_valid', contentType: 'application/xml', contentLength: 2_048, bodySha256: $bodySha256),
                fakturowniaRt3BoundedRetry(),
                [
                    'retry_after_bounded' => true,
                    'attempt_budget_enforced' => true,
                    ...$validProof,
                ],
                $endpoint,
                $maximumResponseBytes,
                $queryContract,
            ),
        ],
    ]);
}

/** @return array<string, mixed> */
function fakturowniaRt3UnsupportedPaymentEvidence(): array
{
    $response = fakturowniaRt3Response(
        'unsupported',
        'runtime_unsupported',
        outcome: 'transport_failure',
        statusCode: null,
        contentType: null,
        contentLength: null,
    );

    return fakturowniaDiagnosticPayloadHash([
        'contract' => Rt3ReadEvidenceContract::Contract,
        'version' => Rt3ReadEvidenceContract::Version,
        'disposition' => Rt3ReadEvidenceContract::Disposition,
        'capability' => 'payment.read.get',
        'provider' => fakturowniaDiagnosticProvider(),
        'harness' => fakturowniaDiagnosticHarness(),
        'run' => fakturowniaDiagnosticRun(),
        'fixture' => [
            'contract' => Rt3ReadEvidenceContract::FixtureContract,
            'version' => Rt3ReadEvidenceContract::Version,
            'sha256' => fakturowniaDiagnosticSha('rt3-payment-fixture'),
            'bytes' => 512,
        ],
        'cases' => [
            fakturowniaRt3Case(
                'runtime_unsupported',
                $response,
                fakturowniaRt3SingleAttempt('transport_failure', null),
                ['dispatch_attempted' => false],
                '/banking/payment/{id}.json',
                8_388_608,
            ),
        ],
    ]);
}

/** @return array<string, mixed> */
function fakturowniaRt6ArtifactEvidence(): array
{
    $sourceReadEvidence = fakturowniaRt3PdfEvidence();
    $pdfSha256 = $sourceReadEvidence['cases'][0]['response']['body_sha256'];
    $connectionSha256 = fakturowniaDiagnosticSha('database-connection');
    $checks = [];

    foreach ([
        'content_addressed_put_confirmed',
        'atomic_descriptor_projection',
        'orphan_recovery',
        'checksum_doctor',
        'shared_database_artifact_lock',
        'lease_owner_revalidated',
        'lease_renewed_before_critical_sections',
        'sealed_purge_authority',
        'purge_permit_consumed_before_delete',
        'forged_purge_permit_denied',
        'replayed_purge_permit_denied',
        'cross_target_purge_permit_denied',
        'native_unserialize_denied',
        'terminal_tombstone',
        'immutable_purge_deadline',
        'ready_to_quarantined_to_deleted',
        'ready_to_deleted_forbidden',
        'truncate_bypass_forbidden',
        'retention_policy_mismatch_blocks_delete',
        'doctor_complete_pagination',
        'full_from_origin_doctor',
        'database_lock_schema_search_path_bound',
        'schema_qualified_artifact_and_lock_tables',
        'database_lock_same_repository_writer_connection',
        'ciphertext_integrity',
        'retention_and_crash_chaos',
    ] as $check) {
        $checks[$check] = true;
    }

    $checks['crash_points_tested'] = 12;
    $checks['doctor_pages_scanned'] = 4;
    $checks['retention_boundary_cases'] = 8;

    return fakturowniaDiagnosticPayloadHash([
        'contract' => Rt6ArtifactEvidenceContract::Contract,
        'version' => Rt6ArtifactEvidenceContract::Version,
        'disposition' => Rt6ArtifactEvidenceContract::Disposition,
        'capability' => 'invoice.pdf.download',
        'provider' => fakturowniaDiagnosticProvider(),
        'harness' => fakturowniaDiagnosticHarness(),
        'run' => fakturowniaDiagnosticRun(),
        'fixture' => [
            'contract' => Rt6ArtifactEvidenceContract::FixtureContract,
            'version' => Rt6ArtifactEvidenceContract::Version,
            'sha256' => fakturowniaDiagnosticSha('rt6-fixture'),
            'bytes' => 16_384,
        ],
        'source_read_evidence' => $sourceReadEvidence,
        'remote_pdf' => [
            'remote_invoice_id_hmac_sha256' => fakturowniaDiagnosticSha('remote-invoice'),
            'remote_snapshot_hmac_sha256' => fakturowniaDiagnosticSha('remote-snapshot'),
            'ksef_terminal_operation_hmac_sha256' => fakturowniaDiagnosticSha('ksef-operation'),
            'source_read_evidence_contract' => Rt3ReadEvidenceContract::Contract,
            'source_read_evidence_version' => Rt3ReadEvidenceContract::Version,
            'source_read_capability' => 'invoice.pdf.stream',
            'source_read_canonical_sha256' => Rt3ReadEvidenceContract::canonicalSha256($sourceReadEvidence),
            'observed_at' => $sourceReadEvidence['cases'][0]['observed_at'],
            'rendering_profile' => 'default_pdf',
            'content_type' => 'application/pdf',
            'bytes' => $sourceReadEvidence['cases'][0]['response']['content_length'],
            'sha256' => $pdfSha256,
            'validation' => 'pdf_magic_and_trailer_valid',
        ],
        'artifact' => [
            'content_address' => 'sha256:'.$pdfSha256,
            'object_sha256' => $pdfSha256,
            'object_bytes' => $sourceReadEvidence['cases'][0]['response']['content_length'],
            'descriptor_sha256' => fakturowniaDiagnosticSha('descriptor'),
            'storage_namespace_hmac_sha256' => fakturowniaDiagnosticSha('storage-namespace'),
            'projection_status' => 'atomic_committed',
            'encryption_contract' => 'database_bound_aes_256_gcm',
            'ciphertext_sha256' => fakturowniaDiagnosticSha('ciphertext'),
            'retention_policy_sha256' => fakturowniaDiagnosticSha('retention-policy'),
            'purge_authority_policy_sha256' => fakturowniaDiagnosticSha('purge-authority-policy'),
            'purge_permit_evidence_sha256' => fakturowniaDiagnosticSha('purge-permit-evidence'),
            'database_lock_scope_hmac_sha256' => fakturowniaDiagnosticSha('lock-scope'),
            'schema_search_path_hmac_sha256' => fakturowniaDiagnosticSha('schema-search-path'),
            'repository_connection_hmac_sha256' => $connectionSha256,
            'writer_connection_hmac_sha256' => $connectionSha256,
            'lease_receipt_sha256' => fakturowniaDiagnosticSha('lease-receipt'),
            'generation' => 3,
            'persisted_at' => '2026-08-26T10:03:00.000000Z',
            'checked_at' => '2026-08-26T10:04:00.000000Z',
        ],
        'checks' => $checks,
    ]);
}

/** @param array<string, mixed> $document */
function fakturowniaExpectInvalidRt3(array $document): void
{
    expect(fn () => Rt3ReadEvidenceContract::assertValid(fakturowniaDiagnosticPayloadHash($document)))
        ->toThrow(InvalidArgumentException::class);
}

/** @param array<string, mixed> $document */
function fakturowniaExpectInvalidRt6(array $document): void
{
    expect(fn () => Rt6ArtifactEvidenceContract::assertValid(fakturowniaDiagnosticPayloadHash($document)))
        ->toThrow(InvalidArgumentException::class);
}

it('accepts only complete versioned RT3 diagnostic evidence without opening a runtime capability', function (): void {
    $json = fakturowniaRt3InvoiceListEvidence();
    $pdf = fakturowniaRt3PdfEvidence();
    $xml = fakturowniaRt3KsefXmlEvidence();
    $upo = fakturowniaRt3KsefXmlEvidence(upo: true);
    $unsupported = fakturowniaRt3UnsupportedPaymentEvidence();

    Rt3ReadEvidenceContract::assertValid($json);
    Rt3ReadEvidenceContract::assertValid($pdf);
    Rt3ReadEvidenceContract::assertValid($xml);
    Rt3ReadEvidenceContract::assertValid($upo);
    Rt3ReadEvidenceContract::assertValid($unsupported);
    $publicMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(Rt3ReadEvidenceContract::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($publicMethods);

    expect(Rt3ReadEvidenceContract::canonicalSha256($json))
        ->toBe('4c1e07256c4733fa13ca198c86278dff5f6fd19aaa19f9b6f22c32a23707bcc4')
        ->and(Rt3ReadEvidenceContract::canonicalSha256($pdf))
        ->toBe('774707c768a3cfe2b8c83a4b17347c6c7d6b9eff5dd5b1c836446d9293e01b95')
        ->and(Rt3ReadEvidenceContract::Disposition)->toBe('diagnostic_only_not_runtime_authority')
        ->and($publicMethods)->toBe(['__serialize', '__unserialize', 'assertValid', 'canonicalSha256']);
});

it('rejects RT3 version hash route coverage retry privacy and binary-proof mutations', function (): void {
    $document = fakturowniaRt3InvoiceListEvidence();

    foreach ([
        static function (array $value): array {
            $value['version'] = '2';

            return $value;
        },
        static function (array $value): array {
            $value['disposition'] = 'runtime_authority';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][0]['request']['method'] = 'POST';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][0]['request']['endpoint_template'] = '/invoices/{id}.json';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][0]['response']['body_sha256'] = fakturowniaDiagnosticSha('private-json-body');

            return $value;
        },
        static function (array $value): array {
            $value['cases'][2]['proof']['unknown_fields_tolerated'] = false;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['pages_scanned'] = 0;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['pages_scanned'] = 101;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['per_page'] = 101;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['items_observed'] = 75;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['items_observed'] = 49;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['pages_scanned'] = 100;
            $value['cases'][3]['proof']['per_page'] = 100;
            $value['cases'][3]['proof']['items_observed'] = 0;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][3]['proof']['stable_ordering'] = false;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][4]['request']['query_contract'] = 'capability_query_v1';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][4]['proof']['pages_scanned'] = 101;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][5]['retry']['attempts'][0]['scheduled_delay_ms'] = null;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][5]['retry']['attempts'][1]['status_code'] = 201;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][5]['retry']['base_delay_ms'] = 251;

            return $value;
        },
        static function (array $value): array {
            $value['cases'][5]['retry']['policy_sha256'] = fakturowniaDiagnosticSha('other-retry-policy');

            return $value;
        },
        static function (array $value): array {
            $value['cases'][5]['response']['content_type'] = 'Application/JSON';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][0]['observed_at'] = '2026-08-26T10:06:00.000000Z';

            return $value;
        },
        static function (array $value): array {
            $value['cases'][1] = $value['cases'][0];

            return $value;
        },
        static function (array $value): array {
            $value['provider']['profile'] = serialize(['hostile' => true]);

            return $value;
        },
        static function (array $value): array {
            $value['run']['environment'] = 'demo_regional';

            return $value;
        },
        static function (array $value): array {
            $value['provider']['hmac_policy']['version'] = '2';

            return $value;
        },
        static function (array $value): array {
            $value['provider']['hmac_policy']['key_id_sha256'] = strtoupper(
                $value['provider']['hmac_policy']['key_id_sha256'],
            );

            return $value;
        },
    ] as $mutation) {
        fakturowniaExpectInvalidRt3($mutation($document));
    }

    $tamperedHash = $document;
    $tamperedHash['payload_sha256'][0] = $tamperedHash['payload_sha256'][0] === '0' ? '1' : '0';
    expect(fn () => Rt3ReadEvidenceContract::assertValid($tamperedHash))
        ->toThrow(InvalidArgumentException::class);

    $pdf = fakturowniaRt3PdfEvidence();
    $pdf['cases'][0]['proof']['pdf_trailer_valid'] = false;
    fakturowniaExpectInvalidRt3($pdf);

    $redirect = fakturowniaRt3PdfEvidence();
    $redirect['cases'][1]['response']['redirect_chain'][0]['credentials_stripped'] = false;
    fakturowniaExpectInvalidRt3($redirect);

    $tooManyRedirects = fakturowniaRt3PdfEvidence();
    $tooManyRedirects['cases'][1]['response']['redirect_chain'] = array_fill(
        0,
        4,
        $tooManyRedirects['cases'][1]['response']['redirect_chain'][0],
    );
    fakturowniaExpectInvalidRt3($tooManyRedirects);

    $credentialsReattached = fakturowniaRt3PdfEvidence();
    $credentialsReattached['cases'][1]['response']['redirect_chain'][] = [
        'status_code' => 302,
        'host_policy_hmac_sha256' => fakturowniaDiagnosticSha('same-host-after-cross-host'),
        'cross_host' => false,
        'credentials_stripped' => false,
    ];
    fakturowniaExpectInvalidRt3($credentialsReattached);

    foreach ([fakturowniaRt3KsefXmlEvidence(), fakturowniaRt3KsefXmlEvidence(upo: true)] as $ksefEvidence) {
        Rt3ReadEvidenceContract::assertValid($ksefEvidence);
        $ksefEvidence['cases'][0]['request']['query_contract'] = 'no_query_v1';
        fakturowniaExpectInvalidRt3($ksefEvidence);
    }
});

it('rejects non-JSON RT3 values native unserialization and sensitive trace disclosure', function (): void {
    foreach ([new stdClass, static fn (): string => 'forbidden', 1.5] as $hostile) {
        $document = fakturowniaRt3InvoiceListEvidence();
        $document['provider']['profile'] = $hostile;
        expect(fn () => Rt3ReadEvidenceContract::assertValid($document))
            ->toThrow(InvalidArgumentException::class);
    }

    $oversized = fakturowniaRt3InvoiceListEvidence();
    $oversized['provider']['profile'] = str_repeat('x', 1_048_577);
    expect(fn () => Rt3ReadEvidenceContract::assertValid($oversized))
        ->toThrow(InvalidArgumentException::class);

    $tooManyCases = fakturowniaRt3InvoiceListEvidence();
    $tooManyCases['cases'] = array_fill(0, 17, $tooManyCases['cases'][0]);
    expect(fn () => Rt3ReadEvidenceContract::assertValid($tooManyCases))
        ->toThrow(InvalidArgumentException::class);

    $class = Rt3ReadEvidenceContract::class;
    $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);
    expect(fn () => unserialize($serialized, ['allowed_classes' => [$class]]))
        ->toThrow(LogicException::class);

    $sentinel = 'rt3-evidence-secret-sentinel-'.str_repeat('x', 48);
    $document = fakturowniaRt3InvoiceListEvidence();
    $document['provider']['unexpected'] = $sentinel;
    $leaked = false;

    try {
        Rt3ReadEvidenceContract::assertValid($document);
    } catch (Throwable $exception) {
        $inspect = function (mixed $value, int $depth = 0) use (&$inspect, &$leaked, $sentinel): void {
            if ($depth > 10 || $leaked) {
                return;
            }

            if (is_string($value)) {
                $leaked = str_contains($value, $sentinel);

                return;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $inspect($item, $depth + 1);
                }
            }
        };
        $inspect($exception->getTrace());
    }

    expect($leaked)->toBeFalse();
});

it('accepts only complete RT6 diagnostic artifact evidence without promoting PDF download', function (): void {
    $document = fakturowniaRt6ArtifactEvidence();
    Rt6ArtifactEvidenceContract::assertValid($document);
    $publicMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(Rt6ArtifactEvidenceContract::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($publicMethods);

    expect(Rt6ArtifactEvidenceContract::canonicalSha256($document))
        ->toBe('84e28ea0231a27ae716d20f2f931e4d56c70376e8cd1a85fc29ff14f29d8baf8')
        ->and(Rt6ArtifactEvidenceContract::Disposition)->toBe('diagnostic_only_not_runtime_authority')
        ->and($publicMethods)->toBe(['__serialize', '__unserialize', 'assertValid', 'canonicalSha256']);
});

it('rejects RT6 version hash source projection lock purge and completeness mutations', function (): void {
    $document = fakturowniaRt6ArtifactEvidence();

    foreach ([
        static function (array $value): array {
            $value['version'] = '0';

            return $value;
        },
        static function (array $value): array {
            $value['capability'] = 'invoice.pdf.stream';

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['source_read_evidence_version'] = '2';

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['source_read_canonical_sha256'] = fakturowniaDiagnosticSha('other-source-document');

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['observed_at'] = '2026-08-26T10:05:00Z';

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['remote_invoice_id_hmac_sha256'] = fakturowniaDiagnosticSha('other-remote-invoice');

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['remote_snapshot_hmac_sha256'] = fakturowniaDiagnosticSha('other-remote-snapshot');

            return $value;
        },
        static function (array $value): array {
            $value['remote_pdf']['ksef_terminal_operation_hmac_sha256'] = fakturowniaDiagnosticSha('other-ksef-operation');

            return $value;
        },
        static function (array $value): array {
            $value['artifact']['content_address'] = 'sha256:'.fakturowniaDiagnosticSha('other');

            return $value;
        },
        static function (array $value): array {
            $value['artifact']['object_bytes']++;

            return $value;
        },
        static function (array $value): array {
            $value['artifact']['writer_connection_hmac_sha256'] = fakturowniaDiagnosticSha('other-writer');

            return $value;
        },
        static function (array $value): array {
            $value['artifact']['generation'] = 0;

            return $value;
        },
        static function (array $value): array {
            $value['checks']['forged_purge_permit_denied'] = false;

            return $value;
        },
        static function (array $value): array {
            unset($value['checks']['doctor_complete_pagination']);

            return $value;
        },
        static function (array $value): array {
            $value['checks']['doctor_pages_scanned'] = 10_001;

            return $value;
        },
        static function (array $value): array {
            $value['fixture']['sha256'] = strtoupper($value['fixture']['sha256']);

            return $value;
        },
        static function (array $value): array {
            $value['provider']['profile'] = serialize(new stdClass);

            return $value;
        },
        static function (array $value): array {
            $value['source_read_evidence']['provider']['connection_scope_hmac_sha256'] = fakturowniaDiagnosticSha('other-source-scope');
            $value['source_read_evidence'] = fakturowniaDiagnosticPayloadHash($value['source_read_evidence']);
            $value['remote_pdf']['source_read_canonical_sha256'] = Rt3ReadEvidenceContract::canonicalSha256($value['source_read_evidence']);

            return $value;
        },
        static function (array $value): array {
            $value['artifact']['persisted_at'] = '2026-08-26T10:01:00.000000Z';

            return $value;
        },
    ] as $mutation) {
        fakturowniaExpectInvalidRt6($mutation($document));
    }

    $tamperedHash = $document;
    $tamperedHash['payload_sha256'][0] = $tamperedHash['payload_sha256'][0] === '0' ? '1' : '0';
    expect(fn () => Rt6ArtifactEvidenceContract::assertValid($tamperedHash))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-JSON RT6 values native unserialization and sensitive trace disclosure', function (): void {
    foreach ([new stdClass, static fn (): string => 'forbidden', 1.5] as $hostile) {
        $document = fakturowniaRt6ArtifactEvidence();
        $document['artifact']['descriptor_sha256'] = $hostile;
        expect(fn () => Rt6ArtifactEvidenceContract::assertValid($document))
            ->toThrow(InvalidArgumentException::class);
    }

    $class = Rt6ArtifactEvidenceContract::class;
    $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);
    expect(fn () => unserialize($serialized, ['allowed_classes' => [$class]]))
        ->toThrow(LogicException::class);

    $sentinel = 'rt6-evidence-secret-sentinel-'.str_repeat('y', 48);
    $document = fakturowniaRt6ArtifactEvidence();
    $document['artifact']['unexpected'] = $sentinel;
    $leaked = false;

    try {
        Rt6ArtifactEvidenceContract::assertValid($document);
    } catch (Throwable $exception) {
        $inspect = function (mixed $value, int $depth = 0) use (&$inspect, &$leaked, $sentinel): void {
            if ($depth > 10 || $leaked) {
                return;
            }

            if (is_string($value)) {
                $leaked = str_contains($value, $sentinel);

                return;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $inspect($item, $depth + 1);
                }
            }
        };
        $inspect($exception->getTrace());
    }

    expect($leaked)->toBeFalse();
});

it('keeps diagnostic evidence guards outside every runtime source dependency', function (): void {
    $sourceRoot = dirname(__DIR__, 2).'/src';
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, '/src/ContractTesting/')) {
            continue;
        }

        $contents = file_get_contents($path);

        if (is_string($contents) && str_contains($contents, 'ContractTesting\\Evidence')) {
            $violations[] = substr($path, strlen($sourceRoot) + 1);
        }
    }

    expect($violations)->toBe([]);
});
