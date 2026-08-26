<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceipt;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\FreshClaimGrant;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\RecoveredConsumedProof;
use Cieplik206\Fakturownia\Tests\Contract\Support\AccountProbeRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\CreateProbeInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\CreateTimedProbeInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\FakturowniaPinnedGuzzleSender;
use Cieplik206\Fakturownia\Tests\Contract\Support\FakturowniaProbeConnector;
use Cieplik206\Fakturownia\Tests\Contract\Support\InvoiceIdentityProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceAttestationGuard;
use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceConsumptionAuthority;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeConfiguration;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeEndpoint;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeFixtureSanitizer;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeLiteralResponse;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeLiteralResponseQueue;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeTransportException;
use Cieplik206\Fakturownia\Tests\Contract\Support\SearchProbeInvoicesRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Saloon\Config;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Helpers\Storage;
use Saloon\Http\Faking\Fixture;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Senders\GuzzleSender;

class_exists(ProbeConfiguration::class);
class_exists(InvoiceIdentityProbe::class);
class_exists(LiveEvidenceAttestationGuard::class);

final class S03FakeConsumptionAuthority implements LiveEvidenceConsumptionAuthority
{
    /** @var array<string, true> */
    private array $consumedRuns = [];

    private int $sequence = 0;

    public int $claimCalls = 0;

    public function __construct(
        #[SensitiveParameter]
        private string $secretKey,
        private readonly bool $alwaysRecover = false,
    ) {}

    /** @param list<array<string, mixed>> $signedAuthorizations */
    public function claim(
        #[SensitiveParameter] array $signedAuthorizations,
        #[SensitiveParameter] ConsumptionClaimRequest $request,
    ): FreshClaimGrant|RecoveredConsumedProof {
        $this->claimCalls++;
        $key = $request->storeIdentitySha256.':'.$request->runId;
        $recovered = $this->alwaysRecover || isset($this->consumedRuns[$key]);

        if (! $recovered) {
            $this->consumedRuns[$key] = true;
        }

        $this->sequence++;
        $secretKey = $this->secretKey;

        if ($secretKey === '') {
            throw new RuntimeException('The consumption authority signing key has already been destroyed.');
        }

        $runStartedAt = new DateTimeImmutable($request->runStartedAt, new DateTimeZone('UTC'));
        $envelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
            $signedAuthorizations,
            $request->toArray(),
            ProbeConfiguration::ConsumptionAuthorityId,
            ['store_id' => $request->storeId, 'sequence' => (string) $this->sequence],
            $runStartedAt,
            $runStartedAt->modify('+1 hour'),
            $recovered
                ? LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition
                : LiveEvidenceAttestationGuard::FreshConsumptionDisposition,
        );
        $receipt = ConsumptionReceipt::fromArray([
            'envelope' => $envelope,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                LiveEvidenceAttestationGuard::canonicalJson($envelope),
                $secretKey,
            )),
        ]);

        return $recovered
            ? new RecoveredConsumedProof($receipt)
            : new FreshClaimGrant($receipt);
    }

    public function __destruct()
    {
        $secretKey = $this->secretKey;
        $this->secretKey = '';

        if ($secretKey !== '') {
            sodium_memzero($secretKey);
        }
    }
}

/** @param list<class-string> $sensitiveObjectTypes */
function s03TraceLeaksSensitiveState(?Throwable $exception, string $sentinel, array $sensitiveObjectTypes): bool
{
    if (! $exception instanceof Throwable) {
        return true;
    }

    $pendingValues = [];
    $visitedExceptions = 0;
    $visitedNodes = 0;

    for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
        if (++$visitedExceptions > 32) {
            return true;
        }

        $pendingValues[] = $current->getMessage();
        $pendingValues[] = $current->getTrace();
    }

    while ($pendingValues !== []) {
        if (++$visitedNodes > 4_096) {
            return true;
        }

        $value = \array_pop($pendingValues);

        foreach ($sensitiveObjectTypes as $sensitiveObjectType) {
            if ($value instanceof $sensitiveObjectType) {
                return true;
            }
        }

        if (\is_string($value)) {
            if (\str_contains($value, $sentinel)) {
                return true;
            }

            continue;
        }

        if (! \is_array($value)) {
            continue;
        }

        foreach ($value as $key => $nestedValue) {
            if (\is_string($key) && \str_contains($key, $sentinel)) {
                return true;
            }

            $pendingValues[] = $nestedValue;
        }
    }

    return false;
}

/** @return array<string, mixed> */
function s03ProbePayload(): array
{
    $template = [
        'department_id' => 1,
        'issue_date' => '2026-08-25',
        'sell_date' => '2026-08-25',
        'payment_to_kind' => 'off',
        'buyer_name' => 'S03 DEMO BUYER',
        'buyer_tax_no' => '1111111111',
        'currency' => 'PLN',
        'positions' => [['name' => 'S03 probe', 'quantity' => '1.00', 'price_net' => '10.00', 'tax' => '23']],
    ];

    return [
        'safety' => ['throwaway_tenants' => true, 'ksef_auto_send_disabled' => true, 'email_delivery_disabled' => true],
        'invoice' => $template,
        'secondary_account_invoice' => $template,
        'correction_invoice' => $template,
        'secondary_department_id' => 2,
    ];
}

/** @return array<string, mixed> */
function s03ProbeVisibilityEvidence(int $distinctDocuments = 1): array
{
    return [
        'complete' => true,
        'found' => $distinctDocuments > 0,
        'distinct_documents' => $distinctDocuments,
        'exact_not_partial' => true,
        'first_visible_after_ms' => $distinctDocuments > 0 ? 2 : null,
        'visibility_window_ms' => 10_000,
        'observation_elapsed_ms' => 10_000,
        'final_boundary_started_after_ms' => 9_900,
        'final_boundary_finished_after_ms' => 9_950,
        'deadline_exhausted' => true,
        'polls' => 2,
        'exact_pages' => 1,
        'partial_pages' => 1,
        'last_http_status' => 200,
        'final_exact_contains_all_observed' => true,
        'final_exact_matches_expected_ids' => true,
    ];
}

/** @return array<string, int> */
function s03ProbeLimits(): array
{
    return [
        'visibility_window_ms' => 10_000,
        'poll_interval_ms' => 250,
        'max_search_pages' => 10,
        'lost_response_timeout_ms' => 2_000,
        'connect_timeout_ms' => 5_000,
        'request_timeout_ms' => 30_000,
        'write_attempt_budget' => ProbeConfiguration::ExactWriteAttemptBudget,
    ];
}

function s03CanonicalRunId(string $runId): string
{
    $normalized = str_replace('-', '', strtolower($runId));

    return str_pad(substr($normalized, 0, 32), 32, '0');
}

/**
 * @return array{
 *     configuration: ProbeConfiguration,
 *     authorization: array<string, mixed>,
 *     signers: array<string, string>,
 *     authority_signers: array<string, string>,
 *     authority: S03FakeConsumptionAuthority,
 *     binding_key: string
 * }
 */
function s03AuthorizedConfiguration(
    ProbeConfiguration $configuration,
    ProbeLiteralResponseQueue $responseQueue,
    ?DateTimeImmutable $now = null,
    bool $alwaysRecover = false,
    int $authorizationLifetimeSeconds = 3_600,
): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $bindingKey = random_bytes(32);
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $authoritySecretKey = sodium_crypto_sign_secretkey($authorityKeyPair);
    $authorityPublicKey = sodium_crypto_sign_publickey($authorityKeyPair);
    $authority = new S03FakeConsumptionAuthority($authoritySecretKey, $alwaysRecover);
    $domain = $configuration->authorizationDomain(
        $bindingKey,
        ProbeConfiguration::offlineLaunchManifestSha256ForTesting(),
    );
    $envelope = LiveEvidenceAttestationGuard::buildUnsignedAuthorizationEnvelope(
        'unit-signer',
        $now->modify('-1 second'),
        $now->modify("+{$authorizationLifetimeSeconds} seconds"),
        $domain['evidence_contract'],
        $domain['challenge'],
        $domain['harness']['repository_commit'],
        $domain['harness']['code_sha256'],
        $domain['harness']['launch_manifest_sha256'],
        $domain['target'],
        $domain['commitments'],
        $domain['consumption'],
        $domain['limits'],
    );
    $authorization = [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            LiveEvidenceAttestationGuard::canonicalUnsignedAuthorizationPayload($envelope),
            $secretKey,
        )),
    ];
    sodium_memzero($secretKey);
    sodium_memzero($authoritySecretKey);
    $signers = ['unit-signer' => base64_encode($publicKey)];
    $authoritySigners = [ProbeConfiguration::ConsumptionAuthorityId => base64_encode($authorityPublicKey)];

    return [
        'configuration' => $configuration->withOperatorAuthorization(
            $authorization,
            $bindingKey,
            $signers,
            $authoritySigners,
            $authority,
            $responseQueue,
        ),
        'authorization' => $authorization,
        'signers' => $signers,
        'authority_signers' => $authoritySigners,
        'authority' => $authority,
        'binding_key' => $bindingKey,
    ];
}

/** @return array<string, mixed> */
function s03ProbeResponseEnvelope(string $classification = 'success'): array
{
    $body = match ($classification) {
        'success' => ['keys' => ['id', 'oid'], 'id' => '<document-id>', 'oid' => '<probe-oid>', 'error_fields' => [], 'duplicate_signals' => ['oid']],
        'duplicate' => ['keys' => ['errors'], 'id' => null, 'oid' => null, 'error_fields' => ['errors'], 'duplicate_signals' => ['oid', 'unique']],
        default => ['keys' => ['errors'], 'id' => null, 'oid' => null, 'error_fields' => ['errors'], 'duplicate_signals' => []],
    };

    return [
        'classification' => $classification,
        'transport' => 'response',
        'http_status' => $classification === 'success' ? 201 : 422,
        'content_type' => 'application/json',
        'request_ids' => [
            'x-request-id' => ['present' => false, 'keyed_digest' => null],
            'x-correlation-id' => ['present' => false, 'keyed_digest' => null],
            'traceparent' => ['present' => false, 'keyed_digest' => null],
        ],
        'body' => $body,
        'normalized_body_sha256' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
    ];
}

/** @return array<string, array<string, mixed>> */
function s03ProbeScenarios(bool $safe = true): array
{
    $visibility = s03ProbeVisibilityEvidence();
    $scenarios = [
        'concurrent_same_oid' => [
            'complete' => true,
            'safe' => true,
            'response_envelopes' => [
                'first' => s03ProbeResponseEnvelope(),
                'second' => s03ProbeResponseEnvelope('duplicate'),
            ],
            'classifications' => ['first' => 'success', 'second' => 'duplicate'],
            'successful_response_document_ids' => 1,
            'distinct_documents' => 1,
            'stored_payload_relation' => 'matches_submitted_payload',
            'visibility' => $visibility,
        ],
        'same_oid_different_payload' => [
            'complete' => true,
            'safe' => true,
            'classification' => 'duplicate',
            'response_envelope' => s03ProbeResponseEnvelope('duplicate'),
            'stored_payload_relation' => 'matches_original_conflicts_variant',
            'distinct_documents' => 1,
        ],
        'lost_response_after_remote_ack' => [
            'complete' => true,
            'safe' => true,
            'failure_mode' => 'transport_timeout_after_single_write_attempt',
            'transport_timeout_observed' => true,
            'transport_failure_kind' => 'timeout_errno_28',
            'write_attempts' => 1,
            'outcome_envelope' => [
                'classification' => 'transport_error',
                'transport' => 'exception',
                'exception_class' => FatalRequestException::class,
            ],
            'document_visible_after_loss' => true,
            'distinct_documents' => 1,
            'visibility' => $visibility,
        ],
        'document_kind_scope' => [
            'complete' => true,
            'safe' => true,
            'scope' => 'per_kind',
            'classifications' => ['vat' => 'success', 'proforma' => 'success', 'correction' => 'success'],
            'response_envelopes' => [
                'vat' => s03ProbeResponseEnvelope(),
                'proforma' => s03ProbeResponseEnvelope(),
                'correction' => s03ProbeResponseEnvelope(),
            ],
            'distinct_documents' => 3,
        ],
        'department_scope' => [
            'complete' => true,
            'safe' => true,
            'scope' => 'per_department',
            'classifications' => ['primary' => 'success', 'secondary' => 'success'],
            'response_envelopes' => [
                'primary' => s03ProbeResponseEnvelope(),
                'secondary' => s03ProbeResponseEnvelope(),
            ],
            'distinct_documents' => 2,
        ],
        'account_scope' => [
            'complete' => true,
            'safe' => true,
            'scope' => 'per_account',
            'response_envelopes' => [
                'primary' => s03ProbeResponseEnvelope(),
                'secondary' => s03ProbeResponseEnvelope(),
            ],
            'primary_visibility' => $visibility,
            'secondary_visibility' => $visibility,
        ],
    ];

    if (! $safe) {
        $scenarios['same_oid_different_payload']['safe'] = false;
        $scenarios['same_oid_different_payload']['stored_payload_relation'] = 'ambiguous_matches_both';
    }

    return $scenarios;
}

/** @return array<string, mixed> */
function s03ProbeFixture(
    string $environment,
    string $generatedAt,
    string $runId,
    bool $safe = true,
): array {
    $runId = s03CanonicalRunId($runId);
    $runFinishedAt = new DateTimeImmutable($generatedAt);
    $runStartedAt = $runFinishedAt->modify('-2 seconds');
    $issuedAt = $runStartedAt->modify('-1 second');
    $limits = s03ProbeLimits();
    $launchManifestSha256 = ProbeConfiguration::offlineLaunchManifestSha256ForTesting();
    $consumptionDomain = [
        'authority_id' => ProbeConfiguration::ConsumptionAuthorityId,
        'authority_policy_sha256' => hash('sha256', 's03-external-atomic-cas-policy-v1'),
        'store_id' => ProbeConfiguration::ConsumptionStoreId,
        'store_identity_sha256' => hash('sha256', 's03-external-atomic-cas-store-v1'),
        'run_id' => $runId,
        'replay_policy' => LiveEvidenceAttestationGuard::ConsumptionReplayPolicy,
    ];
    $authorizationEnvelope = LiveEvidenceAttestationGuard::buildUnsignedAuthorizationEnvelope(
        'test-signer',
        $issuedAt,
        $runFinishedAt->modify('+1 hour'),
        ProbeConfiguration::EvidenceContract,
        base64_encode(str_repeat('c', 32)),
        str_repeat('a', 40),
        str_repeat('b', 64),
        $launchManifestSha256,
        [
            'environment' => $environment,
            'profile' => ProbeConfiguration::AuthorizationProfile,
            'tenant_hmac_sha256' => str_repeat('1', 64),
            'account_hmac_sha256' => str_repeat('2', 64),
        ],
        [
            'scheme' => LiveEvidenceAttestationGuard::CommitmentScheme,
            'configuration_hmac_sha256' => str_repeat('3', 64),
            'policy_hmac_sha256' => str_repeat('4', 64),
            'safety_hmac_sha256' => str_repeat('5', 64),
            'templates_hmac_sha256' => str_repeat('6', 64),
        ],
        $consumptionDomain,
        $limits,
    );
    $operatorAuthorization = [
        'envelope' => $authorizationEnvelope,
        'signature' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
    ];
    $claimRequest = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
        [$operatorAuthorization],
        $runStartedAt,
        base64_encode(str_repeat('n', 32)),
    );
    $authorityEnvelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
        [$operatorAuthorization],
        $claimRequest,
        ProbeConfiguration::ConsumptionAuthorityId,
        ['store_id' => ProbeConfiguration::ConsumptionStoreId, 'sequence' => '1'],
        $runStartedAt,
        $runFinishedAt->modify('+1 hour'),
    );
    $authorizationConsumption = [
        'local_claim' => LiveEvidenceAttestationGuard::buildConsumptionReceipt(
            [$operatorAuthorization],
            $runStartedAt,
        ),
        'authority_receipt' => [
            'envelope' => $authorityEnvelope,
            'signature' => base64_encode(str_repeat('r', SODIUM_CRYPTO_SIGN_BYTES)),
        ],
        'effect_execution_receipts' => [],
    ];
    $scenarios = s03ProbeScenarios($safe);
    $evidence = InvoiceIdentityProbe::resolveVatPilotPolicy($scenarios, $environment);
    $fixture = [
        'probe' => ProbeConfiguration::EvidenceContract,
        'run_id' => $runId,
        'run_started_at' => $runStartedAt->format('Y-m-d\TH:i:s.uP'),
        'run_finished_at' => $runFinishedAt->format('Y-m-d\TH:i:s.uP'),
        'generated_at' => $runFinishedAt->format('Y-m-d\TH:i:s.uP'),
        'environment' => $environment,
        'launch_manifest_sha256' => $launchManifestSha256,
        'probe_limits' => $limits,
        'operator_authorization' => $operatorAuthorization,
        'authorization_consumption' => $authorizationConsumption,
        'write_attempts' => ProbeConfiguration::ExactWriteAttemptBudget,
        'tenant_preflights' => [
            'verified' => true,
            'distinct' => true,
            'identity_basis' => 'environment_account_id',
        ],
        'scenarios' => $scenarios,
        'vat_fixture_evidence' => $evidence,
        'vat_pilot_policy' => InvoiceIdentityProbe::aggregateEnvironmentEvidence([$environment => $evidence]),
    ];
    $fixture['evidence_commitments'] = LiveEvidenceAttestationGuard::evidenceCommitments(
        [$operatorAuthorization],
        $fixture,
        ProbeConfiguration::EvidenceContract,
    );

    return $fixture;
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $mutateEvidenceEnvelope
 * @param  array{secret_key: string, public_key: string}|null  $authorityKeyMaterial
 * @return array{
 *     fixture: array<string, mixed>,
 *     package: array<string, mixed>,
 *     signers: array<string, string>,
 *     authority_signers: array<string, string>,
 *     unsigned_payload: array<string, mixed>
 * }
 */
function s03SignedFixturePackage(
    array $fixture,
    ?callable $mutateEvidenceEnvelope = null,
    #[SensitiveParameter] ?array $authorityKeyMaterial = null,
): array {
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $ownsAuthorityKey = $authorityKeyMaterial === null;
    if ($authorityKeyMaterial === null) {
        $authorityKeyPair = sodium_crypto_sign_keypair();
        $authorityKeyMaterial = [
            'secret_key' => sodium_crypto_sign_secretkey($authorityKeyPair),
            'public_key' => sodium_crypto_sign_publickey($authorityKeyPair),
        ];
    }
    $authoritySecretKey = $authorityKeyMaterial['secret_key'];
    $authorityPublicKey = $authorityKeyMaterial['public_key'];
    $authorization = $fixture['operator_authorization'];
    $signerId = 'test-'.substr($fixture['run_id'], 0, 16);
    $archivedHarness = LiveEvidenceAttestationGuard::harnessSnapshot(
        dirname(__DIR__, 2),
        ProbeConfiguration::EvidenceContract,
    );
    $authorization['envelope']['signer_id'] = $signerId;
    $authorization['envelope']['harness']['code_sha256'] = hash(
        'sha256',
        LiveEvidenceAttestationGuard::canonicalJson($archivedHarness),
    );
    $authorization['signature'] = base64_encode(sodium_crypto_sign_detached(
        LiveEvidenceAttestationGuard::canonicalUnsignedAuthorizationPayload($authorization['envelope']),
        $secretKey,
    ));
    $fixture['operator_authorization'] = $authorization;
    $runStartedAt = new DateTimeImmutable($fixture['run_started_at']);
    $runFinishedAt = new DateTimeImmutable($fixture['run_finished_at']);
    $claimRequest = LiveEvidenceAttestationGuard::buildConsumptionClaimRequest(
        [$authorization],
        $runStartedAt,
        base64_encode(str_repeat('n', 32)),
    );
    $authorityEnvelope = LiveEvidenceAttestationGuard::buildConsumptionAuthorityEnvelopeForTesting(
        [$authorization],
        $claimRequest,
        ProbeConfiguration::ConsumptionAuthorityId,
        ['store_id' => ProbeConfiguration::ConsumptionStoreId, 'sequence' => '1'],
        $runStartedAt,
        $runFinishedAt->modify('+1 hour'),
    );

    if ($authoritySecretKey === '') {
        throw new RuntimeException('The consumption authority signing key cannot be empty.');
    }

    $fixture['authorization_consumption'] = [
        'local_claim' => LiveEvidenceAttestationGuard::buildConsumptionReceipt(
            [$authorization],
            $runStartedAt,
        ),
        'authority_receipt' => [
            'envelope' => $authorityEnvelope,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                LiveEvidenceAttestationGuard::canonicalJson($authorityEnvelope),
                $authoritySecretKey,
            )),
        ],
        'effect_execution_receipts' => [],
    ];
    $fixture['evidence_commitments'] = LiveEvidenceAttestationGuard::evidenceCommitments(
        [$authorization],
        $fixture,
        ProbeConfiguration::EvidenceContract,
    );
    $fixturePath = 'tests/Fixtures/Contract/invoice-identity-'.$fixture['environment'].'-'.$fixture['run_id'].'.json';
    $fixtureJson = json_encode(
        $fixture,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
    $authorizationEnvelope = $authorization['envelope'];
    $evidencePayload = LiveEvidenceAttestationGuard::buildUnsignedEvidencePayload(
        ProbeConfiguration::EvidenceContract,
        $fixturePath,
        hash('sha256', $fixtureJson),
        $authorizationEnvelope['harness']['repository_commit'],
        $authorizationEnvelope['harness']['code_sha256'],
        $authorizationEnvelope['harness']['launch_manifest_sha256'],
        $archivedHarness,
        $runStartedAt,
        $runFinishedAt,
        $fixture['environment'],
        $fixture['authorization_consumption'],
        [[
            'profile' => ProbeConfiguration::AuthorizationProfile,
            'challenge' => $authorizationEnvelope['challenge'],
            'sha256' => LiveEvidenceAttestationGuard::signedDocumentSha256($authorization),
        ]],
        $fixture['evidence_commitments'],
    );
    $evidenceEnvelope = LiveEvidenceAttestationGuard::buildEvidenceEnvelopeForTesting(
        $evidencePayload,
        $signerId,
        $runFinishedAt->modify('+1 second'),
        $runFinishedAt->modify('+1 day'),
    );
    if ($mutateEvidenceEnvelope !== null) {
        $evidenceEnvelope = $mutateEvidenceEnvelope($evidenceEnvelope);
    }
    $signedEvidence = [
        'envelope' => $evidenceEnvelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            LiveEvidenceAttestationGuard::canonicalJson($evidenceEnvelope),
            $secretKey,
        )),
    ];
    sodium_memzero($secretKey);
    if ($ownsAuthorityKey) {
        sodium_memzero($authoritySecretKey);
    }

    return [
        'fixture' => $fixture,
        'package' => [
            'fixture_path' => $fixturePath,
            'fixture' => $fixture,
            'signed_evidence' => $signedEvidence,
        ],
        'signers' => [$signerId => base64_encode($publicKey)],
        'authority_signers' => [ProbeConfiguration::ConsumptionAuthorityId => base64_encode($authorityPublicKey)],
        'unsigned_payload' => $evidencePayload,
    ];
}

it('requires a fingerprinted throwaway DEMO tenant', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $endpoint = new ProbeEndpoint('demo_pl', "https://{$host}/", 'token', ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'));

    expect($endpoint->baseUrl)->toBe("https://{$host}")
        ->and(fn () => new ProbeEndpoint('demo_pl', 'https://production.fakturownia.pl', 'token', str_repeat('a', 64)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProbeEndpoint('demo_regional', "https://{$host}", 'token', str_repeat('a', 64)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $endpoint->verifyAccountId('2'))->toThrow(RuntimeException::class)
        ->and(fn () => ProbeEndpoint::accountIdentityFor('demo_pl', 'echo-account'))->toThrow(InvalidArgumentException::class)
        ->and(ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'))->not->toBe(ProbeEndpoint::fingerprintFor('demo_pl', $host, '2'))
        ->and(ProbeEndpoint::accountIdentityFor('demo_pl', '1'))->toBe(ProbeEndpoint::accountIdentityFor('demo_pl', '1'))
        ->and(ProbeEndpoint::accountIdentityFor('demo_pl', '1'))->not->toBe(ProbeEndpoint::accountIdentityFor('demo_regional', '1'));
});

it('binds security-critical probe functions to PHP globals despite namespace overrides', function (): void {
    if (! function_exists('Cieplik206\\Fakturownia\\Tests\\Contract\\Support\\hash')) {
        eval('namespace Cieplik206\\Fakturownia\\Tests\\Contract\\Support; function hash(string $algorithm, string $value): string { return str_repeat("0", 64); } function random_bytes(int $length): string { return str_repeat("\\0", $length); }');
    }

    $host = 's03-demo-primary.fakturownia.pl';
    $expectedFingerprint = hash('sha256', "fakturownia-s0.3|demo_pl|{$host}|1");
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$host}", 'primary-token', $expectedFingerprint),
        new ProbeEndpoint(
            'demo_pl',
            'https://s03-demo-secondary.fakturownia.pl',
            'secondary-token',
            ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-secondary.fakturownia.pl', '2'),
        ),
        s03ProbePayload(),
    );
    $probe = new InvoiceIdentityProbe($configuration);
    $comparisonKey = (new ReflectionProperty(InvoiceIdentityProbe::class, 'payloadComparisonKey'))->getValue($probe);

    expect(ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'))->toBe($expectedFingerprint)
        ->and($comparisonKey)->toBeString()
        ->and($comparisonKey)->not->toBe(str_repeat("\0", 32));
});

it('rejects every non-plain base URL component', function (string $url): void {
    expect(fn () => new ProbeEndpoint('demo_pl', $url, 'token', str_repeat('a', 64)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'user' => 'https://user@s03-demo-primary.fakturownia.pl',
    'password' => 'https://user:password@s03-demo-primary.fakturownia.pl',
    'port' => 'https://s03-demo-primary.fakturownia.pl:443',
    'query' => 'https://s03-demo-primary.fakturownia.pl?probe=yes',
    'fragment' => 'https://s03-demo-primary.fakturownia.pl#probe',
]);

it('rejects the live environment before reading credentials or attempting any HTTP', function (): void {
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($temporaryRoot === false) {
        throw new RuntimeException('Could not resolve the S0.3 environment fixture parent.');
    }

    $directory = $temporaryRoot.'/fakturownia-s03-environment-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700) || ! chmod($directory, 0700)) {
        throw new RuntimeException('Could not create the S0.3 environment fixture directory.');
    }

    $payloadPath = $directory.'/payload.json';
    $authorizationPath = $directory.'/authorization.json';
    $bindingKeyPath = $directory.'/binding-key.txt';
    $files = [
        $payloadPath => json_encode(s03ProbePayload(), JSON_THROW_ON_ERROR),
        $authorizationPath => '{"envelope":[]}',
        $bindingKeyPath => base64_encode(random_bytes(32)),
    ];
    foreach ($files as $path => $contents) {
        if (file_put_contents($path, $contents) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Could not initialize an S0.3 owner-only environment fixture.');
        }
    }

    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $environment = [
        'FAKTUROWNIA_CONTRACT_PROBE_PAYLOAD_FILE' => $payloadPath,
        'FAKTUROWNIA_CONTRACT_PROBE_OPERATOR_AUTHORIZATION_FILE' => $authorizationPath,
        'FAKTUROWNIA_CONTRACT_PROBE_AUTHORIZATION_BINDING_KEY_FILE' => $bindingKeyPath,
        'FAKTUROWNIA_CONTRACT_PROBE_ENVIRONMENT' => 'demo_pl',
        'FAKTUROWNIA_CONTRACT_PROBE_BASE_URL' => "https://{$primaryHost}",
        'FAKTUROWNIA_CONTRACT_PROBE_TOKEN' => 'primary-token',
        'FAKTUROWNIA_CONTRACT_PROBE_TENANT_FINGERPRINT' => ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1'),
        'FAKTUROWNIA_CONTRACT_PROBE_SECONDARY_ENVIRONMENT' => 'demo_pl',
        'FAKTUROWNIA_CONTRACT_PROBE_SECONDARY_BASE_URL' => "https://{$secondaryHost}",
        'FAKTUROWNIA_CONTRACT_PROBE_SECONDARY_TOKEN' => 'secondary-token',
        'FAKTUROWNIA_CONTRACT_PROBE_SECONDARY_TENANT_FINGERPRINT' => ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2'),
        'FAKTUROWNIA_CONTRACT_PROBE_LOST_RESPONSE_TIMEOUT_MS' => '50',
    ];
    $original = [];
    foreach ($environment as $name => $value) {
        $original[$name] = getenv($name);
        putenv("{$name}={$value}");
    }
    try {
        expect(fn () => ProbeConfiguration::fromEnvironment())
            ->toThrow(RuntimeException::class, ProbeConfiguration::BrokeredEffectExecutionUnavailable);
    } finally {
        foreach ($original as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
        foreach ([$payloadPath, $authorizationPath, $bindingKeyPath] as $path) {
            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory) && ! is_link($directory)) {
            rmdir($directory);
        }
    }
});

it('freezes the inclusive live lost-response timeout boundaries', function (): void {
    ProbeConfiguration::assertLiveLimits(10_000, 250, 10, 1_000, 5_000, 30_000);
    ProbeConfiguration::assertLiveLimits(10_000, 250, 10, 10_000, 5_000, 30_000);

    expect(fn () => ProbeConfiguration::assertLiveLimits(10_000, 250, 10, 999, 5_000, 30_000))
        ->toThrow(InvalidArgumentException::class, 'frozen safe range')
        ->and(fn () => ProbeConfiguration::assertLiveLimits(10_000, 250, 10, 10_001, 5_000, 30_000))
        ->toThrow(InvalidArgumentException::class, 'frozen safe range');
});

it('requires two isolated tenants and forbids delivery fields', function (): void {
    $endpoint = fn (string $name): ProbeEndpoint => new ProbeEndpoint('demo_pl', "https://s03-demo-{$name}.fakturownia.pl", 'token', ProbeEndpoint::fingerprintFor('demo_pl', "s03-demo-{$name}.fakturownia.pl", $name === 'primary' ? '1' : '2'));
    $payload = s03ProbePayload();

    $configuration = new ProbeConfiguration($endpoint('primary'), $endpoint('secondary'), $payload);

    expect($configuration->primary->environment)->toBe('demo_pl')
        ->and(fn () => new ProbeConfiguration($endpoint('primary'), $endpoint('secondary'), [...$payload, 'invoice' => [...$payload['invoice'], 'buyer_email' => 'buyer@example.com']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProbeConfiguration($endpoint('primary'), $endpoint('secondary'), [...$payload, 'invoice' => [...$payload['invoice'], 'nested' => ['send_to_ksef' => []]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreateProbeInvoiceRequest('token', ['nested' => ['gov_save_and_send' => false]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreateTimedProbeInvoiceRequest('token', ['nested' => ['send_to_ksef' => false]], 1_000, 25))->toThrow(InvalidArgumentException::class);
});

it('removes tokens, PII, payloads and authorization data from every exception trace frame', function (): void {
    $previousExceptionArgumentSetting = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $sentinels = [
        'S03_TRACE_REQUEST_TOKEN',
        'S03_TRACE_REQUEST_PII',
        'S03_TRACE_PRIMARY_TOKEN',
        'S03_TRACE_SECONDARY_TOKEN',
        'S03_TRACE_CONFIG_PII',
        'S03_TRACE_SIGNED_AUTHORIZATION',
        'S03_TRACE_BINDING_KEY_1234567890',
    ];
    $exceptions = [];

    try {
        try {
            new CreateProbeInvoiceRequest($sentinels[0], [
                'nested' => ['buyer_email' => $sentinels[1]],
            ]);
        } catch (Throwable $exception) {
            $exceptions[] = $exception;
        }

        $primaryHost = 's03-demo-primary.fakturownia.pl';
        $secondaryHost = 's03-demo-secondary.fakturownia.pl';
        $payload = s03ProbePayload();
        $payload['invoice']['nested'] = ['buyer_email' => $sentinels[4]];

        try {
            new ProbeConfiguration(
                new ProbeEndpoint('demo_pl', "https://{$primaryHost}", $sentinels[2], ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
                new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", $sentinels[3], ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
                $payload,
                10_000,
                250,
                10,
                2_000,
                5_000,
                30_000,
                ['sentinel' => $sentinels[5]],
                str_pad($sentinels[6], 32, 'x'),
            );
        } catch (Throwable $exception) {
            $exceptions[] = $exception;
        }

        expect($exceptions)->toHaveCount(2);
        foreach ($exceptions as $exception) {
            foreach ($sentinels as $sentinel) {
                expect(s03TraceLeaksSensitiveState(
                    $exception,
                    $sentinel,
                    [CreateProbeInvoiceRequest::class, ProbeConfiguration::class, ProbeEndpoint::class],
                ))->toBeFalse();
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previousExceptionArgumentSetting);
    }
});

it('rejects every re-signed mutation of the authorization binding and expired authorization', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $bindingKey = random_bytes(32);
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $signers = ['unit-signer' => base64_encode($publicKey)];
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $authoritySecretKey = sodium_crypto_sign_secretkey($authorityKeyPair);
    $authorityPublicKey = sodium_crypto_sign_publickey($authorityKeyPair);
    $authoritySigners = [ProbeConfiguration::ConsumptionAuthorityId => base64_encode($authorityPublicKey)];
    $authority = new S03FakeConsumptionAuthority($authoritySecretKey);
    $domain = $configuration->authorizationDomain(
        $bindingKey,
        ProbeConfiguration::offlineLaunchManifestSha256ForTesting(),
    );
    $baseEnvelope = LiveEvidenceAttestationGuard::buildUnsignedAuthorizationEnvelope(
        'unit-signer',
        $now->modify('-1 second'),
        $now->modify('+1 hour'),
        $domain['evidence_contract'],
        $domain['challenge'],
        $domain['harness']['repository_commit'],
        $domain['harness']['code_sha256'],
        $domain['harness']['launch_manifest_sha256'],
        $domain['target'],
        $domain['commitments'],
        $domain['consumption'],
        $domain['limits'],
    );
    $sign = static fn (array $envelope): array => [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(
            LiveEvidenceAttestationGuard::canonicalJson($envelope),
            $secretKey,
        )),
    ];
    $responseQueue = ProbeLiteralResponseQueue::from();

    try {
        expect($configuration->withOperatorAuthorization(
            $sign($baseEnvelope),
            $bindingKey,
            $signers,
            $authoritySigners,
            $authority,
            $responseQueue,
        )->assertTrustedOperatorAuthorization($now))->toHaveKeys(['envelope', 'signature']);

        $mutations = [
            'evidence contract' => static function (array &$envelope): void {
                $envelope['evidence_contract'] = 'fakturownia-ksef-demo-s0.4-v1';
            },
            'challenge' => static function (array &$envelope): void {
                $envelope['challenge'] = base64_encode(str_repeat('x', 32));
            },
            'repository commit' => static function (array &$envelope): void {
                $envelope['harness']['repository_commit'] = str_repeat('f', 40);
            },
            'code digest' => static function (array &$envelope): void {
                $envelope['harness']['code_sha256'] = str_repeat('f', 64);
            },
            'launch manifest digest' => static function (array &$envelope): void {
                $envelope['harness']['launch_manifest_sha256'] = str_repeat('f', 64);
            },
            'environment' => static function (array &$envelope): void {
                $envelope['target']['environment'] = 'demo_regional';
            },
            'profile' => static function (array &$envelope): void {
                $envelope['target']['profile'] = 'other_profile';
            },
            'tenant commitment' => static function (array &$envelope): void {
                $envelope['target']['tenant_hmac_sha256'] = str_repeat('8', 64);
            },
            'account commitment' => static function (array &$envelope): void {
                $envelope['target']['account_hmac_sha256'] = str_repeat('8', 64);
            },
            'commitment scheme' => static function (array &$envelope): void {
                $envelope['commitments']['scheme'] = 'unsafe';
            },
            'configuration commitment' => static function (array &$envelope): void {
                $envelope['commitments']['configuration_hmac_sha256'] = str_repeat('8', 64);
            },
            'policy commitment' => static function (array &$envelope): void {
                $envelope['commitments']['policy_hmac_sha256'] = str_repeat('8', 64);
            },
            'safety commitment' => static function (array &$envelope): void {
                $envelope['commitments']['safety_hmac_sha256'] = str_repeat('8', 64);
            },
            'template commitment' => static function (array &$envelope): void {
                $envelope['commitments']['templates_hmac_sha256'] = str_repeat('8', 64);
            },
            'claim authority ID' => static function (array &$envelope): void {
                $envelope['consumption']['authority_id'] = 'other-authority';
            },
            'claim authority policy' => static function (array &$envelope): void {
                $envelope['consumption']['authority_policy_sha256'] = str_repeat('8', 64);
            },
            'claim store ID' => static function (array &$envelope): void {
                $envelope['consumption']['store_id'] = 'other-store';
            },
            'claim store identity' => static function (array &$envelope): void {
                $envelope['consumption']['store_identity_sha256'] = str_repeat('8', 64);
            },
            'run id' => static function (array &$envelope): void {
                $envelope['consumption']['run_id'] = str_repeat('8', 32);
            },
            'replay policy' => static function (array &$envelope): void {
                $envelope['consumption']['replay_policy'] = 'unsafe_retry';
            },
        ];
        foreach (array_keys(s03ProbeLimits()) as $limit) {
            $mutations['limit '.$limit] = static function (array &$envelope) use ($limit): void {
                $envelope['limits'][$limit]++;
            };
        }

        foreach ($mutations as $mutate) {
            $mutatedEnvelope = $baseEnvelope;
            $mutate($mutatedEnvelope);
            $mutatedConfiguration = $configuration->withOperatorAuthorization(
                $sign($mutatedEnvelope),
                $bindingKey,
                $signers,
                $authoritySigners,
                $authority,
                $responseQueue,
            );

            expect(fn () => $mutatedConfiguration->assertTrustedOperatorAuthorization($now))
                ->toThrow(Exception::class);
        }

        $expiredEnvelope = $baseEnvelope;
        $expiredEnvelope['issued_at'] = $now->modify('-2 hours')->format('Y-m-d\TH:i:s.u\Z');
        $expiredEnvelope['expires_at'] = $now->modify('-1 hour')->format('Y-m-d\TH:i:s.u\Z');
        expect(fn () => $configuration->withOperatorAuthorization(
            $sign($expiredEnvelope),
            $bindingKey,
            $signers,
            $authoritySigners,
            $authority,
            $responseQueue,
        )->assertTrustedOperatorAuthorization($now))->toThrow(InvalidArgumentException::class);
    } finally {
        sodium_memzero($secretKey);
        sodium_memzero($authoritySecretKey);
    }
});

it('never lets an explicit signer test seam reach real transport', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration($configuration, $responseQueue);

    expect(fn () => $configuration->withOperatorAuthorization(
        $authorized['authorization'],
        $authorized['binding_key'],
        $authorized['signers'],
        $authorized['authority_signers'],
        $authorized['authority'],
    ))->toThrow(InvalidArgumentException::class, 'literal response queue');
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('rejects missing fixtures and callable mocks without a provider fallback or fixture write', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'missing-fixture-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
    );
    $fixtureName = 's03-missing-'.bin2hex(random_bytes(12));
    $fixturePath = "/tmp/{$fixtureName}.json";
    $missingFixtureMock = new MockClient([new Fixture($fixtureName, new Storage('/tmp'))]);
    $callableCalls = 0;
    $callableMock = new MockClient([
        static function () use (&$callableCalls): MockResponse {
            $callableCalls++;

            return MockResponse::make(['id' => 999], 201);
        },
    ]);
    $senderResolverCalls = 0;
    Config::setSenderResolver(static function () use (&$senderResolverCalls): GuzzleSender {
        $senderResolverCalls++;

        return new GuzzleSender;
    });

    try {
        expect($fixturePath)->not->toBeFile()
            ->and(fn () => (new FakturowniaProbeConnector("https://{$primaryHost}", 5_000, 30_000))->withMockClient($missingFixtureMock))
            ->toThrow(LogicException::class, 'general Saloon MockClient')
            ->and(fn () => (new FakturowniaProbeConnector("https://{$primaryHost}", 5_000, 30_000))->withMockClient($callableMock))
            ->toThrow(LogicException::class, 'general Saloon MockClient')
            ->and(fn () => (new FakturowniaProbeConnector("https://{$primaryHost}", 5_000, 30_000))->send(
                new AccountProbeRequest('missing-fixture-token'),
                $missingFixtureMock,
            ))
            ->toThrow(ProbeTransportException::class, 'without exposing request credentials')
            ->and(fn () => (new FakturowniaProbeConnector("https://{$primaryHost}", 5_000, 30_000))->send(
                (new AccountProbeRequest('missing-fixture-token'))->withMockClient($callableMock),
            ))
            ->toThrow(ProbeTransportException::class, 'without exposing request credentials')
            ->and(fn () => (new InvoiceIdentityProbe($authorized['configuration']))->run())
            ->toThrow(RuntimeException::class, 'preflight failed');
    } finally {
        Config::setSenderResolver(null);
    }

    expect($fixturePath)->not->toBeFile()
        ->and($callableCalls)->toBe(0)
        ->and($senderResolverCalls)->toBe(0)
        ->and($responseQueue->dispatchAttempts())->toBe(1)
        ->and($responseQueue->consumedResponses())->toBe(0)
        ->and($authorized['authority']->claimCalls)->toBe(0);
});

it('rejects callable-like values at the sealed literal queue type boundary', function (): void {
    $factory = new ReflectionMethod(ProbeLiteralResponseQueue::class, 'from');
    $callableCalls = 0;
    $callable = static function () use (&$callableCalls): ProbeLiteralResponse {
        $callableCalls++;

        return ProbeLiteralResponse::json(['id' => 1]);
    };

    expect(fn () => $factory->invoke(null, $callable))
        ->toThrow(TypeError::class)
        ->and(fn () => ProbeLiteralResponse::json(['callback' => $callable]))
        ->toThrow(InvalidArgumentException::class, 'objects, callables or resources')
        ->and($callableCalls)->toBe(0);

    $referencedValue = 'sealed-before-validation';
    $referencedHeader = 'sealed-header';
    $literal = ProbeLiteralResponse::json(
        ['value' => &$referencedValue],
        headers: ['X-S03-Sealed' => &$referencedHeader],
        expectedRequestClass: AccountProbeRequest::class,
    );
    $referencedValue = $callable;
    $referencedHeader = "mutated\r\nX-Injected: yes";
    $responseQueue = ProbeLiteralResponseQueue::from($literal);
    $response = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue($responseQueue)
        ->send(new AccountProbeRequest('sealed-reference-token'));

    expect($response->json('value'))->toBe('sealed-before-validation')
        ->and($response->header('X-S03-Sealed'))->toBe('sealed-header')
        ->and($callableCalls)->toBe(0)
        ->and($responseQueue->dispatchAttempts())->toBe(1)
        ->and($responseQueue->consumedResponses())->toBe(1);
});

it('rejects connector and request middleware that could bypass the sealed queue', function (): void {
    $responseQueue = ProbeLiteralResponseQueue::from();
    $connectorRequestCalls = 0;
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue($responseQueue);
    $connector->middleware()->onRequest(static function () use (&$connectorRequestCalls): MockResponse {
        $connectorRequestCalls++;

        return MockResponse::make(['id' => 777], 200);
    }, 's03-local-connector-bypass');

    expect(fn () => $connector->send(new AccountProbeRequest('local-middleware-token')))
        ->toThrow(ProbeTransportException::class, 'without exposing request credentials');

    $requestCalls = 0;
    $responseCalls = 0;
    $fatalCalls = 0;
    $request = new AccountProbeRequest('local-request-middleware-token');
    $request->middleware()
        ->onRequest(static function () use (&$requestCalls): MockResponse {
            $requestCalls++;

            return MockResponse::make(['id' => 888], 200);
        }, 's03-local-request-bypass')
        ->onResponse(static function () use (&$responseCalls): void {
            $responseCalls++;
        }, 's03-local-response-bypass')
        ->onFatalException(static function () use (&$fatalCalls): void {
            $fatalCalls++;
        }, 's03-local-fatal-bypass');
    $requestConnector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue($responseQueue);

    expect(fn () => $requestConnector->send($request))
        ->toThrow(ProbeTransportException::class, 'without exposing request credentials')
        ->and($connectorRequestCalls)->toBe(0)
        ->and($requestCalls)->toBe(0)
        ->and($responseCalls)->toBe(0)
        ->and($fatalCalls)->toBe(0)
        ->and($responseQueue->dispatchAttempts())->toBe(0)
        ->and($responseQueue->consumedResponses())->toBe(0);
});

it('redacts request objects from synchronous and asynchronous transport failure traces', function (): void {
    $sentinelToken = 's03-direct-transport-trace-sentinel';
    $retryHandlerCalls = 0;
    $retryHandler = static function () use (&$retryHandlerCalls, $sentinelToken): void {
        $retryHandlerCalls++;
        throw new RuntimeException($sentinelToken);
    };
    $syncQueues = [
        ProbeLiteralResponseQueue::from(),
        ProbeLiteralResponseQueue::from(ProbeLiteralResponse::timeout(AccountProbeRequest::class)),
    ];

    foreach ($syncQueues as $index => $responseQueue) {
        $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
            ->withLiteralResponseQueue($responseQueue);

        try {
            $connector->send(new AccountProbeRequest($sentinelToken));
            $exception = null;
        } catch (ProbeTransportException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(ProbeTransportException::class)
            ->and($exception?->getPrevious())->toBeNull()
            ->and($exception?->getMessage())->not->toContain($sentinelToken)
            ->and(s03TraceLeaksSensitiveState($exception, $sentinelToken, [AccountProbeRequest::class]))->toBeFalse()
            ->and($responseQueue->dispatchAttempts())->toBe(1)
            ->and($responseQueue->consumedResponses())->toBe($index);
    }

    $retryQueue = ProbeLiteralResponseQueue::from();
    $retryConnector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue($retryQueue);
    try {
        $retryConnector->send(new AccountProbeRequest($sentinelToken), null, $retryHandler);
        $retryException = null;
    } catch (ProbeTransportException $caught) {
        $retryException = $caught;
    }

    expect($retryException)->toBeInstanceOf(ProbeTransportException::class)
        ->and($retryException?->getMessage())->not->toContain($sentinelToken)
        ->and(s03TraceLeaksSensitiveState($retryException, $sentinelToken, [AccountProbeRequest::class]))->toBeFalse()
        ->and($retryHandlerCalls)->toBe(0)
        ->and($retryQueue->dispatchAttempts())->toBe(1)
        ->and($retryQueue->consumedResponses())->toBe(0);

    $asyncQueue = ProbeLiteralResponseQueue::from(ProbeLiteralResponse::timeout(AccountProbeRequest::class));
    $asyncConnector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue($asyncQueue);
    try {
        $asyncConnector->sendAsync(new AccountProbeRequest($sentinelToken))->wait();
        $asyncException = null;
    } catch (ProbeTransportException $caught) {
        $asyncException = $caught;
    }

    expect($asyncException)->toBeInstanceOf(ProbeTransportException::class)
        ->and($asyncException?->getPrevious())->toBeNull()
        ->and($asyncException?->getMessage())->not->toContain($sentinelToken)
        ->and(s03TraceLeaksSensitiveState($asyncException, $sentinelToken, [AccountProbeRequest::class]))->toBeFalse()
        ->and($asyncQueue->dispatchAttempts())->toBe(1)
        ->and($asyncQueue->consumedResponses())->toBe(1);
});

it('rejects global Saloon mocks and middleware before credentials or provider branding', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $sentinelToken = 's03-global-state-sentinel-token';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", $sentinelToken, ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $globalMockCalls = 0;
    $globalMock = MockClient::global([
        CreateProbeInvoiceRequest::class => static function () use (&$globalMockCalls): MockResponse {
            $globalMockCalls++;

            return MockResponse::make(['id' => 999], 201);
        },
    ]);

    try {
        new InvoiceIdentityProbe($configuration);
        $mockException = null;
    } catch (RuntimeException $exception) {
        $mockException = $exception;
    } finally {
        MockClient::destroyGlobal();
    }

    $requestMiddlewareCalls = 0;
    $responseMiddlewareCalls = 0;
    $fatalMiddlewareCalls = 0;
    Config::globalMiddleware()
        ->onRequest(static function () use (&$requestMiddlewareCalls): void {
            $requestMiddlewareCalls++;
        }, 's03-adversarial-request')
        ->onResponse(static function () use (&$responseMiddlewareCalls): void {
            $responseMiddlewareCalls++;
        }, 's03-adversarial-response')
        ->onFatalException(static function () use (&$fatalMiddlewareCalls): void {
            $fatalMiddlewareCalls++;
        }, 's03-adversarial-fatal');

    try {
        new InvoiceIdentityProbe($configuration);
        $middlewareException = null;
    } catch (RuntimeException $exception) {
        $middlewareException = $exception;
    } finally {
        Config::clearGlobalMiddleware();
    }

    expect($mockException)->toBeInstanceOf(RuntimeException::class)
        ->and($mockException?->getMessage())->toContain('global Saloon mock')
        ->and(s03TraceLeaksSensitiveState($mockException, $sentinelToken, [ProbeConfiguration::class, ProbeEndpoint::class]))->toBeFalse()
        ->and($middlewareException)->toBeInstanceOf(RuntimeException::class)
        ->and($middlewareException?->getMessage())->toContain('global Saloon middleware')
        ->and(s03TraceLeaksSensitiveState($middlewareException, $sentinelToken, [ProbeConfiguration::class, ProbeEndpoint::class]))->toBeFalse()
        ->and($globalMockCalls)->toBe(0)
        ->and($globalMock->getRecordedResponses())->toBe([])
        ->and($requestMiddlewareCalls)->toBe(0)
        ->and($responseMiddlewareCalls)->toBe(0)
        ->and($fatalMiddlewareCalls)->toBe(0);
});

it('pins the real sender and every security-relevant Guzzle option against mutable Saloon defaults', function (): void {
    $originalSender = Config::$defaultSender;
    $originalTlsMethod = Config::$defaultTlsMethod;
    $originalConnectionTimeout = Config::$defaultConnectionTimeout;
    $originalRequestTimeout = Config::$defaultRequestTimeout;
    $resolverCalls = 0;
    Config::$defaultSender = GuzzleSender::class;
    Config::$defaultTlsMethod = 0;
    Config::$defaultConnectionTimeout = 999;
    Config::$defaultRequestTimeout = 999;
    Config::setSenderResolver(static function () use (&$resolverCalls): GuzzleSender {
        $resolverCalls++;

        return new GuzzleSender;
    });

    try {
        $connector = new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000);
        $sender = $connector->sender();
        $pendingRequest = $connector->createPendingRequest(new AccountProbeRequest('sender-pin-token'));

        expect($sender)->toBeInstanceOf(FakturowniaPinnedGuzzleSender::class)
            ->and($resolverCalls)->toBe(0)
            ->and($pendingRequest->config()->get('crypto_method'))->toBe(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)
            ->and($pendingRequest->config()->get('connect_timeout'))->toBe(5.0)
            ->and($pendingRequest->config()->get('timeout'))->toBe(30.0)
            ->and($pendingRequest->config()->get('verify'))->toBeTrue()
            ->and($pendingRequest->config()->get('proxy'))->toBe('')
            ->and($pendingRequest->config()->get('allow_redirects'))->toBeFalse()
            ->and($pendingRequest->config()->get('http_errors'))->toBeTrue()
            ->and($pendingRequest->config()->get('stream'))->toBeFalse();
    } finally {
        Config::setSenderResolver(null);
        Config::$defaultSender = $originalSender;
        Config::$defaultTlsMethod = $originalTlsMethod;
        Config::$defaultConnectionTimeout = $originalConnectionTimeout;
        Config::$defaultRequestTimeout = $originalRequestTimeout;
    }
});

it('blocks an unsupervised provider run before resolving a sender or issuing HTTP', function (): void {
    $resolverCalls = 0;
    Config::setSenderResolver(static function () use (&$resolverCalls): GuzzleSender {
        $resolverCalls++;

        return new GuzzleSender;
    });
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $probe = new InvoiceIdentityProbe(new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'unsupervised-primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'unsupervised-secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    ));

    try {
        expect(fn () => $probe->run())
            ->toThrow(RuntimeException::class, ProbeConfiguration::BrokeredEffectExecutionUnavailable);
    } finally {
        Config::setSenderResolver(null);
    }

    expect($resolverCalls)->toBe(0);
});

it('never promotes a sealed offline run or test evidence payload to canonical live evidence', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
    );
    $probe = new InvoiceIdentityProbe($authorized['configuration']);
    $testEvidence = s03SignedFixturePackage(
        s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', str_repeat('e', 32)),
    );

    expect(fn () => $probe->assertRealProviderTransportOrigin())
        ->toThrow(RuntimeException::class, 'real provider transport')
        ->and(fn () => $authorized['configuration']->publishUnsignedEvidenceSidecar($testEvidence['unsigned_payload']))
        ->toThrow(InvalidArgumentException::class, 'cannot publish a canonical live sidecar');
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('atomically removes a valid mock fixture when live sidecar publication is denied', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
    );
    $configuration = $authorized['configuration'];
    $runStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $configuration->claimOperatorAuthorization($runStartedAt);
    $runFinishedAt = $runStartedAt->modify('+2 seconds');
    $runId = $configuration->consumptionClaimRequest()->runId;
    $fixture = s03ProbeFixture('demo_pl', $runFinishedAt->format('Y-m-d\TH:i:s.uP'), $runId);
    $fixture['operator_authorization'] = $authorized['authorization'];
    $fixture['authorization_consumption'] = $configuration->authorizationConsumptionReceipt();
    $fixture['evidence_commitments'] = LiveEvidenceAttestationGuard::evidenceCommitments(
        [$authorized['authorization']],
        $fixture,
        ProbeConfiguration::EvidenceContract,
    );
    $fixtureBase = __DIR__."/../Fixtures/Contract/invoice-identity-demo_pl-{$runId}";
    $paths = [
        $fixtureBase.'.json',
        $fixtureBase.'.authorization-'.ProbeConfiguration::AuthorizationProfile.'.json',
        $fixtureBase.'.attestation.unsigned.json',
    ];
    $writer = new ReflectionMethod(InvoiceIdentityProbe::class, 'writeFixture');

    foreach ($paths as $path) {
        expect($path)->not->toBeFile();
    }
    expect(fn () => $writer->invoke(
        new InvoiceIdentityProbe($configuration),
        $fixture,
        $runId,
        $runStartedAt,
        $runFinishedAt,
    ))->toThrow(InvalidArgumentException::class, 'cannot publish a canonical live sidecar');
    foreach ($paths as $path) {
        expect($path)->not->toBeFile();
    }
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('requires disjoint operator and authority key material before claiming a run', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration($configuration, $responseQueue);
    $sharedKeyConfiguration = $configuration->withOperatorAuthorization(
        $authorized['authorization'],
        $authorized['binding_key'],
        $authorized['signers'],
        [ProbeConfiguration::ConsumptionAuthorityId => array_values($authorized['signers'])[0]],
        $authorized['authority'],
        $responseQueue,
    );

    expect(fn () => $sharedKeyConfiguration->claimOperatorAuthorization(new DateTimeImmutable('now', new DateTimeZone('UTC'))))
        ->toThrow(InvalidArgumentException::class, 'key material')
        ->and($authorized['authority']->claimCalls)->toBe(0);
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('rejects a recovered authority proof before any mutating HTTP', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => '1'], expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => '2'], expectedRequestClass: AccountProbeRequest::class),
    );
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
        alwaysRecover: true,
    );

    expect(fn () => (new InvoiceIdentityProbe($authorized['configuration']))->run())
        ->toThrow(InvalidArgumentException::class, 'recovered consumption proof')
        ->and($authorized['authority']->claimCalls)->toBe(1);
    expect($responseQueue->consumedResponses())->toBe(2);
});

it('rejects an authorization replay with a fresh process nonce before any mutating HTTP', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => '1'], expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => '2'], expectedRequestClass: AccountProbeRequest::class),
    );
    $authorized = s03AuthorizedConfiguration($configuration, $responseQueue);
    $authorized['configuration']->claimOperatorAuthorization(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $replayedConfiguration = $configuration->withOperatorAuthorization(
        $authorized['authorization'],
        $authorized['binding_key'],
        $authorized['signers'],
        $authorized['authority_signers'],
        $authorized['authority'],
        $responseQueue,
    );

    expect(fn () => (new InvoiceIdentityProbe($replayedConfiguration))->run())
        ->toThrow(InvalidArgumentException::class, 'recovered consumption proof')
        ->and($authorized['authority']->claimCalls)->toBe(2);
    expect($responseQueue->consumedResponses())->toBe(2);
});

it('rechecks the operator and authority windows immediately before every write boundary', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
        authorizationLifetimeSeconds: 20,
    );
    $authorized['configuration']->claimOperatorAuthorization(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    expect(fn () => $authorized['configuration']->assertEffectAuthorizedNow())
        ->toThrow(InvalidArgumentException::class, 'expires before');
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('revalidates one verified fresh authority grant for the exact eleven-effect budget', function (): void {
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $responseQueue = ProbeLiteralResponseQueue::from();
    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration(
            new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, '1')),
            new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
            s03ProbePayload(),
        ),
        $responseQueue,
    );
    $configuration = $authorized['configuration'];
    $configuration->claimOperatorAuthorization(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $configuration->assertEffectAuthorizedNow(ProbeConfiguration::ExactWriteAttemptBudget);
    $configuration->assertExactWriteBudgetConsumed();

    expect($configuration->consumedWriteAttempts())->toBe(ProbeConfiguration::ExactWriteAttemptBudget)
        ->and(fn () => $configuration->assertEffectAuthorizedNow())->toThrow(RuntimeException::class, 'budget');
    expect($responseQueue->consumedResponses())->toBe(0);
});

it('fails tenant isolation before the first invoice POST when both hosts resolve to one account', function (): void {
    $accountId = '17';
    $primaryHost = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $primary = new ProbeEndpoint('demo_pl', "https://{$primaryHost}", 'primary-token', ProbeEndpoint::fingerprintFor('demo_pl', $primaryHost, $accountId));
    $secondary = new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'secondary-token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, $accountId));
    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => $accountId], expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => $accountId], expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => 1, 'oid' => 'unexpected'], 201, expectedRequestClass: CreateProbeInvoiceRequest::class),
    );

    $authorized = s03AuthorizedConfiguration(
        new ProbeConfiguration($primary, $secondary, s03ProbePayload()),
        $responseQueue,
    );
    $probe = new InvoiceIdentityProbe($authorized['configuration']);

    expect(fn () => $probe->run())->toThrow(RuntimeException::class, 'same account');

    expect($responseQueue->consumedResponses())->toBe(2);
});

it('builds account, create and paged exact OID requests without live HTTP', function (): void {
    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => 10], expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => 1, 'oid' => 'order-1'], 201, expectedRequestClass: CreateProbeInvoiceRequest::class),
        ProbeLiteralResponse::json(['id' => 2, 'oid' => 'order-2'], 201, expectedRequestClass: CreateTimedProbeInvoiceRequest::class),
        ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class),
    );
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))->withLiteralResponseQueue($responseQueue);
    $connector->send(new AccountProbeRequest('token'));
    $connector->send(new CreateProbeInvoiceRequest('token', ['oid' => 'order-1']));
    $connector->send(new CreateTimedProbeInvoiceRequest('token', ['oid' => 'order-2'], 1_000, 25));
    $connector->send(new SearchProbeInvoicesRequest('token', 'order-1', 2));

    $create = $connector->createPendingRequest(new CreateProbeInvoiceRequest('token', ['oid' => 'order-1']));
    $timed = $connector->createPendingRequest(new CreateTimedProbeInvoiceRequest('token', ['oid' => 'order-2'], 1_000, 25));
    $search = $connector->createPendingRequest(new SearchProbeInvoicesRequest('token', 'order-1', 2));
    expect($responseQueue->consumedResponses())->toBe(4)
        ->and($create->body()->all())->toBe(['api_token' => 'token', 'invoice' => ['oid' => 'order-1']])
        ->and($timed->config()->get('timeout'))->toBe(0.025)
        ->and($timed->config()->get('allow_redirects'))->toBeFalse()
        ->and($search->query()->get('page'))->toBe(2);
});

it('rejects malformed success and loose duplicate responses', function (): void {
    $responseQueue = ProbeLiteralResponseQueue::from(...array_map(
        static fn (array $entry): ProbeLiteralResponse => ProbeLiteralResponse::json(
            $entry['body'],
            $entry['status'],
            expectedRequestClass: CreateProbeInvoiceRequest::class,
        ),
        [
            ['body' => ['id' => 1, 'oid' => 'order-1'], 'status' => 201],
            ['body' => ['id' => 1, 'oid' => 'order-1', 'errors' => ['warning']], 'status' => 200],
            ['body' => ['errors' => ['oid' => ['must be unique']]], 'status' => 422],
            ['body' => ['errors' => ['oid' => ['has bad format']]], 'status' => 422],
            ['body' => ['errors' => ['oid' => ['must be unique']]], 'status' => 400],
            ['body' => ['oid' => 'order-1', 'errors' => ['buyer_name' => ['must be unique']]], 'status' => 409],
            ['body' => ['message' => 'Buyer must be unique; OID has bad format'], 'status' => 409],
            ['body' => ['id' => 1, 'oid' => 'order-1', 'status' => 'error'], 'status' => 200],
            ['body' => ['id' => 1, 'oid' => 'order-1', 'code' => 500], 'status' => 200],
            ['body' => ['id' => 1, 'oid' => 'order-1', 'message' => null], 'status' => 200],
            ['body' => ['id' => 1, 'oid' => 'order-1', 'errors' => null], 'status' => 200],
            ['body' => ['invoice' => ['id' => 1, 'oid' => 'order-1']], 'status' => 200],
            ['body' => ['id' => true, 'oid' => 'order-1'], 'status' => 200],
        ],
    ));
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))->withLiteralResponseQueue($responseQueue);
    $responses = array_map(fn (): mixed => $connector->send(new CreateProbeInvoiceRequest('token', ['oid' => 'order-1'])), range(1, 13));

    expect(array_map([InvoiceIdentityProbe::class, 'classify'], $responses))->toBe([
        'success',
        'other_error',
        'duplicate',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
        'other_error',
    ]);
});

it('keeps observing through the full window and detects a late duplicate', function (): void {
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        'https://s03-demo-primary.fakturownia.pl',
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-primary.fakturownia.pl', '1'),
    );
    $payload = s03ProbePayload();
    $configuration = new ProbeConfiguration($endpoint, new ProbeEndpoint(
        'demo_pl',
        'https://s03-demo-secondary.fakturownia.pl',
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-secondary.fakturownia.pl', '2'),
    ), $payload, visibilityWindowMs: 50, pollIntervalMs: 5, maxSearchPages: 1);
    $responses = [
        ProbeLiteralResponse::json([['id' => 1, 'oid' => 's03-late-duplicate']], expectedRequestClass: SearchProbeInvoicesRequest::class),
        ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class),
    ];
    for ($poll = 0; $poll < 20; $poll++) {
        $responses[] = ProbeLiteralResponse::json([
            ['id' => 1, 'oid' => 's03-late-duplicate'],
            ['id' => 2, 'oid' => 's03-late-duplicate'],
        ], expectedRequestClass: SearchProbeInvoicesRequest::class);
        $responses[] = ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class);
    }
    $responseQueue = ProbeLiteralResponseQueue::from(...$responses);
    $configuration = $configuration->withLiteralResponseQueueForTesting($responseQueue);
    $method = new ReflectionMethod(InvoiceIdentityProbe::class, 'observe');
    $visibility = $method->invoke(new InvoiceIdentityProbe($configuration), $endpoint, 's03-late-duplicate', hrtime(true));

    expect($responseQueue->consumedResponses())->toBeGreaterThan(2)
        ->and($visibility)->toBeArray()
        ->and($visibility['distinct_documents'])->toBe(2)
        ->and($visibility['distinct_documents'] === 1 && $visibility['exact_not_partial'])->toBeFalse();
});

it('rejects a document that disappears from the final exact boundary poll', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        "https://{$host}",
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'),
    );
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration($endpoint, new ProbeEndpoint(
        'demo_pl',
        "https://{$secondaryHost}",
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2'),
    ), s03ProbePayload(), visibilityWindowMs: 50, pollIntervalMs: 5, maxSearchPages: 1);
    $responses = [
        ProbeLiteralResponse::json([['id' => 1, 'oid' => 's03-disappears']], expectedRequestClass: SearchProbeInvoicesRequest::class),
        ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class),
    ];
    for ($poll = 0; $poll < 20; $poll++) {
        $responses[] = ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class);
        $responses[] = ProbeLiteralResponse::json([], expectedRequestClass: SearchProbeInvoicesRequest::class);
    }
    $responseQueue = ProbeLiteralResponseQueue::from(...$responses);
    $configuration = $configuration->withLiteralResponseQueueForTesting($responseQueue);
    $method = new ReflectionMethod(InvoiceIdentityProbe::class, 'observe');
    $visibility = $method->invoke(new InvoiceIdentityProbe($configuration), $endpoint, 's03-disappears', hrtime(true), ['1']);

    expect($responseQueue->consumedResponses())->toBeGreaterThan(2)
        ->and($visibility)->toBeArray()
        ->and($visibility['complete'])->toBeFalse()
        ->and($visibility['exact_not_partial'])->toBeFalse()
        ->and($visibility['final_exact_contains_all_observed'])->toBeFalse()
        ->and($visibility['final_exact_matches_expected_ids'])->toBeFalse();
});

it('does not start a late visibility page and clamps page timeouts to the signed deadline', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        "https://{$host}",
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'),
    );
    $configuration = new ProbeConfiguration(
        $endpoint,
        new ProbeEndpoint(
            'demo_pl',
            'https://s03-demo-secondary.fakturownia.pl',
            'token',
            ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-secondary.fakturownia.pl', '2'),
        ),
        s03ProbePayload(),
        maxSearchPages: 10,
        connectTimeoutMs: 5_000,
        requestTimeoutMs: 30_000,
    );
    $documents = array_map(
        static fn (int $id): array => ['id' => $id, 'oid' => 's03-deadline'],
        range(1, 100),
    );
    $responseQueue = ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json(
        $documents,
        expectedRequestClass: SearchProbeInvoicesRequest::class,
        delayMicroseconds: 25_000,
        maximumConnectTimeoutSeconds: 0.02,
        maximumRequestTimeoutSeconds: 0.02,
    ));
    $configuration = $configuration->withLiteralResponseQueueForTesting($responseQueue);
    $method = new ReflectionMethod(InvoiceIdentityProbe::class, 'searchAll');
    $result = $method->invoke(
        new InvoiceIdentityProbe($configuration),
        $endpoint,
        's03-deadline',
        's03-deadline',
        hrtime(true) + 20_000_000,
    );

    expect($result)->toBeArray()
        ->and($result['complete'])->toBeFalse()
        ->and($result['pages'])->toBe(1)
        ->and($responseQueue->consumedResponses())->toBe(1);
});

it('fails exact search closed on malformed and error-envelope documents', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        "https://{$host}",
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', $host, '1'),
    );
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        $endpoint,
        new ProbeEndpoint(
            'demo_pl',
            "https://{$secondaryHost}",
            'token',
            ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2'),
        ),
        s03ProbePayload(),
        maxSearchPages: 1,
    );
    $search = new ReflectionMethod(InvoiceIdentityProbe::class, 'searchAll');

    foreach ([
        [['id' => 1, 'oid' => 's03-search', 'status' => 'error']],
        [['id' => true, 'oid' => 's03-search']],
        [['invoice' => ['id' => 1, 'oid' => 's03-search']]],
        [['id' => 1, 'oid' => 's03-search', 'errors' => null]],
        [['id' => 1, 'oid' => 's03-search', 'account' => ['errors' => []]]],
        [['id' => 1, 'oid' => 's03-search', 'success' => false]],
    ] as $body) {
        $probe = new InvoiceIdentityProbe($configuration->withLiteralResponseQueueForTesting(
            ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json($body, expectedRequestClass: SearchProbeInvoicesRequest::class)),
        ));
        $result = $search->invoke($probe, $endpoint, 's03-search', 's03-search', hrtime(true) + 1_000_000_000);
        expect($result)->toBeArray()->and($result['complete'])->toBeFalse();
    }

    $probe = new InvoiceIdentityProbe($configuration->withLiteralResponseQueueForTesting(
        ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json(
            [['id' => 1, 'oid' => 's03-search']],
            expectedRequestClass: SearchProbeInvoicesRequest::class,
        )),
    ));
    $result = $search->invoke($probe, $endpoint, 's03-search', 's03-search', hrtime(true) + 1_000_000_000);
    expect($result)->toBeArray()->and($result['complete'])->toBeTrue();
});

it('replaces a preflight transport exception that could contain the token', function (): void {
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        'https://s03-demo-primary.fakturownia.pl',
        'secret-token',
        ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-primary.fakturownia.pl', '1'),
    );
    $payload = s03ProbePayload();
    $configuration = new ProbeConfiguration($endpoint, new ProbeEndpoint(
        'demo_pl',
        'https://s03-demo-secondary.fakturownia.pl',
        'secondary-token',
        ProbeEndpoint::fingerprintFor('demo_pl', 's03-demo-secondary.fakturownia.pl', '2'),
    ), $payload)->withLiteralResponseQueueForTesting(ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::timeout(AccountProbeRequest::class),
    ));

    try {
        $method = new ReflectionMethod(InvoiceIdentityProbe::class, 'preflight');
        $method->invoke(new InvoiceIdentityProbe($configuration), $endpoint);
        $exception = null;
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toBe('The DEMO account preflight failed before an account ID could be verified.')
        ->not->toContain('secret-token');
});

it('accepts only the trusted top-level account identity shape', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $endpoint = new ProbeEndpoint(
        'demo_pl',
        "https://{$host}",
        'token',
        ProbeEndpoint::fingerprintFor('demo_pl', $host, '11'),
    );
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        $endpoint,
        new ProbeEndpoint(
            'demo_pl',
            "https://{$secondaryHost}",
            'token',
            ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '12'),
        ),
        s03ProbePayload(),
    );
    $preflight = new ReflectionMethod(InvoiceIdentityProbe::class, 'preflight');
    $invalidBodies = [
        ['account' => ['id' => 11]],
        ['id' => 11, 'account' => ['id' => 12]],
        ['id' => 11, 'account' => 'echo-account'],
        ['id' => 11, 'account' => ['name' => 'echo-account']],
        ['id' => 11, 'account' => ['id' => 11, 'errors' => []]],
        ['id' => 11, 'errors' => []],
        ['id' => 11, 'message' => 'account echo'],
        ['id' => 11, 'status' => 'error'],
        ['id' => 11, 'status' => '422'],
        ['id' => 11, 'status' => 'unauthorized'],
        ['id' => 11, 'code' => 500],
        ['id' => 11, 'success' => false],
        ['id' => 11, 'ok' => false],
        ['id' => true],
        ['id' => 'echo-account'],
    ];

    foreach ($invalidBodies as $body) {
        $probe = new InvoiceIdentityProbe($configuration->withLiteralResponseQueueForTesting(
            ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json($body, expectedRequestClass: AccountProbeRequest::class)),
        ));
        expect(fn () => $preflight->invoke($probe, $endpoint))
            ->toThrow(RuntimeException::class, 'did not return an account ID');
    }

    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => 11], 201, expectedRequestClass: AccountProbeRequest::class),
        ProbeLiteralResponse::json(['id' => 1], 201, expectedRequestClass: CreateProbeInvoiceRequest::class),
    );
    $probe = new InvoiceIdentityProbe($configuration->withLiteralResponseQueueForTesting($responseQueue));
    expect(fn () => $preflight->invoke($probe, $endpoint))
        ->toThrow(RuntimeException::class, 'did not return an account ID')
        ->and($responseQueue->consumedResponses())->toBe(1);

    $probe = new InvoiceIdentityProbe($configuration->withLiteralResponseQueueForTesting(
        ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json(
            ['id' => 11, 'account' => ['id' => '11']],
            expectedRequestClass: AccountProbeRequest::class,
        )),
    ));
    expect($preflight->invoke($probe, $endpoint))->toBe(ProbeEndpoint::accountIdentityFor('demo_pl', '11'));
});

it('rejects successful response IDs that never become visible', function (): void {
    $responseQueue = ProbeLiteralResponseQueue::from(
        ProbeLiteralResponse::json(['id' => 1, 'oid' => 'order-1'], 201),
        ProbeLiteralResponse::json(['id' => 2, 'oid' => 'order-1'], 201),
    );
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))->withLiteralResponseQueue($responseQueue);
    $responses = [
        $connector->send(new CreateProbeInvoiceRequest('token', ['oid' => 'order-1'])),
        $connector->send(new CreateProbeInvoiceRequest('token', ['oid' => 'order-1'])),
    ];

    expect(InvoiceIdentityProbe::responseIdsMatchVisibility($responses, [['id' => 1, 'oid' => 'order-1']]))->toBeFalse()
        ->and(InvoiceIdentityProbe::responseIdsMatchVisibility($responses, [['id' => 1], ['id' => 2]]))->toBeTrue();
});

it('recognizes only transport timeout outcomes as lost responses', function (): void {
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))
        ->withLiteralResponseQueue(ProbeLiteralResponseQueue::from());
    $pendingRequest = $connector->createPendingRequest(new CreateTimedProbeInvoiceRequest('token', ['oid' => 'order-1'], 1_000, 25));
    $psrRequest = new PsrRequest('POST', 'https://s03-demo-primary.fakturownia.pl/invoices.json');
    $timeout = new FatalRequestException(new ConnectException('cURL error 28', $psrRequest, null, ['errno' => 28]), $pendingRequest);
    $connectionFailure = new FatalRequestException(new ConnectException('cURL error 7', $psrRequest, null, ['errno' => 7]), $pendingRequest);

    expect(InvoiceIdentityProbe::isTimeoutOutcome($timeout))->toBeTrue()
        ->and(InvoiceIdentityProbe::isTimeoutOutcome($connectionFailure))->toBeFalse()
        ->and(InvoiceIdentityProbe::isTimeoutOutcome(new RuntimeException('Operation timed out after 25 milliseconds')))->toBeFalse();
});

it('redacts credentials, payload values and PII from fixture envelopes', function (): void {
    $payload = s03ProbePayload();
    $endpoint = fn (string $name): ProbeEndpoint => new ProbeEndpoint('demo_pl', "https://s03-demo-{$name}.fakturownia.pl", "{$name}-token", ProbeEndpoint::fingerprintFor('demo_pl', "s03-demo-{$name}.fakturownia.pl", $name === 'primary' ? '1' : '2'));
    $configuration = new ProbeConfiguration($endpoint('primary'), $endpoint('secondary'), $payload);
    $responseQueue = ProbeLiteralResponseQueue::from(ProbeLiteralResponse::json(
        ['id' => 1, 'oid' => 's03-order', 'errors' => ['buyer@example.com S03 DEMO BUYER 1111111111']],
        422,
        [
            'Content-Type' => 'application/json; profile="https://secret.example"',
            'X-Request-Id' => 'buyer@example.com/raw-request-id',
            'X-Correlation-Id' => 'S03 DEMO BUYER correlation',
        ],
    ));
    $connector = (new FakturowniaProbeConnector('https://s03-demo-primary.fakturownia.pl', 5_000, 30_000))->withLiteralResponseQueue($responseQueue);
    $response = $connector->send(new SearchProbeInvoicesRequest('secret-token', 's03-order', 1));
    $sanitizer = new ProbeFixtureSanitizer(['secret-token', ...$configuration->sensitivePayloadValues()]);
    $secondSanitizer = new ProbeFixtureSanitizer(['secret-token', ...$configuration->sensitivePayloadValues()]);
    $evidence = $sanitizer->response($response, 's03-order');
    $secondEvidence = $secondSanitizer->response($response, 's03-order');
    $requestIdDigest = $evidence['request_ids']['x-request-id']['keyed_digest'];
    $secondRequestIdDigest = $secondEvidence['request_ids']['x-request-id']['keyed_digest'];
    $json = json_encode($evidence, JSON_THROW_ON_ERROR);

    expect($sanitizer->isSafe($json))->toBeTrue()
        ->and($configuration->sensitivePayloadValues())->toContain('S03 DEMO BUYER')->toContain('1111111111')->toContain('S03 probe')
        ->and($json)->not->toContain('secret-token')->not->toContain('S03 DEMO BUYER')->not->toContain('1111111111')->not->toContain('secret.example')->not->toContain('buyer@example.com/raw-request-id')
        ->and($evidence['request_ids']['x-request-id']['present'])->toBeTrue()
        ->and($requestIdDigest)->toMatch('/^[a-f0-9]{64}$/')
        ->and($secondRequestIdDigest)->toMatch('/^[a-f0-9]{64}$/')
        ->and($requestIdDigest)->not->toBe($secondRequestIdDigest)
        ->and($requestIdDigest)->not->toBe(hash('sha256', 'buyer@example.com/raw-request-id'))
        ->and($secondRequestIdDigest)->not->toBe(hash('sha256', 'buyer@example.com/raw-request-id'));
});

it('compares every submitted business field without persisting deterministic payload digests', function (): void {
    $payload = s03ProbePayload();
    $endpoint = fn (string $name): ProbeEndpoint => new ProbeEndpoint('demo_pl', "https://s03-demo-{$name}.fakturownia.pl", 'token', ProbeEndpoint::fingerprintFor('demo_pl', "s03-demo-{$name}.fakturownia.pl", $name === 'primary' ? '1' : '2'));
    $configuration = new ProbeConfiguration($endpoint('primary'), $endpoint('secondary'), $payload);
    $probe = new InvoiceIdentityProbe($configuration);
    $secondProbe = new InvoiceIdentityProbe($configuration);
    $storedProof = new ReflectionMethod(InvoiceIdentityProbe::class, 'storedProof');
    $fingerprint = new ReflectionMethod(InvoiceIdentityProbe::class, 'fingerprint');
    $canonicalize = new ReflectionMethod(InvoiceIdentityProbe::class, 'canonicalizeByTemplate');
    $expected = [...$payload['correction_invoice'], 'kind' => 'correction', 'oid' => 'order-1', 'oid_unique' => 'yes', 'internal_note' => 's0.3-correction', 'invoice_id' => 41, 'from_invoice_id' => 41, 'custom_business_field' => 'expected'];
    $visibility = ['complete' => true, 'exact_not_partial' => true, 'documents' => [$expected]];
    $baselineDigest = $fingerprint->invoke($probe, $expected, $expected);
    $secondProbeDigest = $fingerprint->invoke($secondProbe, $expected, $expected);
    $canonicalPayload = $canonicalize->invoke($probe, $expected, $expected);
    $plainSha256 = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));
    $position = $expected['positions'][0];
    $mutations = [
        [...$expected, 'department_id' => 2],
        [...$expected, 'issue_date' => '2026-08-26'],
        [...$expected, 'sell_date' => '2026-08-26'],
        [...$expected, 'payment_to_kind' => 'other_date'],
        [...$expected, 'buyer_name' => 'CHANGED BUYER'],
        [...$expected, 'buyer_tax_no' => '2222222222'],
        [...$expected, 'currency' => 'EUR'],
        [...$expected, 'positions' => [[...$position, 'name' => 'changed position']]],
        [...$expected, 'positions' => [[...$position, 'quantity' => '2.00']]],
        [...$expected, 'positions' => [[...$position, 'price_net' => '11.00']]],
        [...$expected, 'positions' => [[...$position, 'tax' => '8']]],
        [...$expected, 'kind' => 'vat'],
        [...$expected, 'oid' => 'order-2'],
        [...$expected, 'internal_note' => 's0.3-changed'],
        [...$expected, 'invoice_id' => 42],
        [...$expected, 'from_invoice_id' => 42],
        [...$expected, 'custom_business_field' => 'changed'],
    ];

    expect($storedProof->invoke($probe, $visibility, [$expected]))->toBeTrue()
        ->and($storedProof->invoke($probe, [...$visibility, 'documents' => [[...$expected, 'custom_business_field' => 'different']]], [$expected]))->toBeFalse()
        ->and($storedProof->invoke($probe, [...$visibility, 'documents' => [[...$expected, 'from_invoice_id' => 42]]], [$expected]))->toBeFalse()
        ->and($baselineDigest)->toBeString()->toMatch('/^[a-f0-9]{64}$/')
        ->and($secondProbeDigest)->toBeString()->toMatch('/^[a-f0-9]{64}$/')
        ->and($baselineDigest)->not->toBe($secondProbeDigest)
        ->and($baselineDigest)->not->toBe($plainSha256)
        ->and($fingerprint->invoke($probe, [...$expected, 'oid_unique' => 'no'], [...$expected, 'oid_unique' => 'no']))->toBe($baselineDigest);

    foreach ($mutations as $mutation) {
        expect($fingerprint->invoke($probe, $mutation, $mutation))->not->toBe($baselineDigest);
    }

    $fixture = s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', '0001-0001-0001-0001-0001');
    $serializedFixture = json_encode($fixture, JSON_THROW_ON_ERROR);
    $legacyDigestFixture = $fixture;
    $legacyDigestFixture['scenarios']['concurrent_same_oid']['payload_sha256'] = $plainSha256;

    expect($serializedFixture)->not->toContain($baselineDigest)->not->toContain($plainSha256)
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($legacyDigestFixture))->toBeFalse();

    $source = file_get_contents(__DIR__.'/../Contract/Support/InvoiceIdentityProbe.php');
    expect($source)->toBeString()
        ->not->toContain('stored_payload_fingerprint')
        ->not->toContain('original_payload_fingerprint')
        ->not->toContain('variant_payload_fingerprint');
});

it('fails the VAT policy closed when any gate is unsafe or inconclusive', function (): void {
    $scenarios = array_fill_keys(['concurrent_same_oid', 'same_oid_different_payload', 'lost_response_after_remote_ack', 'document_kind_scope', 'department_scope', 'account_scope'], ['complete' => true, 'safe' => true, 'scope' => 'verified']);
    $scenarios['same_oid_different_payload']['safe'] = false;
    $unsafe = InvoiceIdentityProbe::resolveVatPilotPolicy($scenarios, 'demo_pl');
    $scenarios['same_oid_different_payload']['complete'] = false;
    $inconclusive = InvoiceIdentityProbe::resolveVatPilotPolicy($scenarios, 'demo_pl');

    $matrix = InvoiceIdentityProbe::aggregateEnvironmentEvidence(['demo_pl' => $unsafe, 'demo_regional' => [...$unsafe, 'complete' => true, 'safe' => true]]);
    $safePl = InvoiceIdentityProbe::resolveVatPilotPolicy(array_fill_keys(array_keys($scenarios), ['complete' => true, 'safe' => true, 'scope' => 'verified']), 'demo_pl');
    $safeRegional = InvoiceIdentityProbe::resolveVatPilotPolicy(array_fill_keys(array_keys($scenarios), ['complete' => true, 'safe' => true, 'scope' => 'verified']), 'demo_regional');
    $completeMatrix = InvoiceIdentityProbe::aggregateEnvironmentEvidence(['demo_pl' => $safePl, 'demo_regional' => $safeRegional]);
    $spoofedMatrix = InvoiceIdentityProbe::aggregateEnvironmentEvidence(['demo_pl' => $safePl, 'demo_regional' => $safePl]);
    $malformedMatrix = InvoiceIdentityProbe::aggregateEnvironmentEvidence(['demo_pl' => [...$safePl, 'scope' => 'invalid'], 'demo_regional' => $safeRegional]);

    expect($unsafe['remote_identity_policy_candidate'])->toBe('no_remote_uniqueness')
        ->and($inconclusive['failure_disposition'])->toBe('inconclusive_manual_review')
        ->and($matrix['remote_identity_policy'])->toBe('no_remote_uniqueness')
        ->and($spoofedMatrix['remote_identity_policy'])->toBe('no_remote_uniqueness')
        ->and($malformedMatrix['remote_identity_policy'])->toBe('no_remote_uniqueness')
        ->and($completeMatrix['remote_identity_policy'])->toBe('business_oid');
});

it('validates the complete fixture schema and recomputes evidence from scenarios', function (): void {
    $fixture = s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', '0001-0001-0001-0001-0001');
    $withoutPreflights = $fixture;
    unset($withoutPreflights['tenant_preflights']);
    $multipleWriteAttempts = $fixture;
    $multipleWriteAttempts['scenarios']['lost_response_after_remote_ack']['write_attempts'] = 2;
    $missingTimeoutEvidence = $fixture;
    $missingTimeoutEvidence['scenarios']['lost_response_after_remote_ack']['transport_timeout_observed'] = false;
    $inconsistentResolution = $fixture;
    $inconsistentResolution['scenarios']['same_oid_different_payload']['safe'] = false;
    $classificationEnvelopeMismatch = $fixture;
    $classificationEnvelopeMismatch['scenarios']['document_kind_scope']['classifications']['vat'] = 'transport_error';
    $successWithServerErrorStatus = $fixture;
    $successWithServerErrorStatus['scenarios']['document_kind_scope']['response_envelopes']['vat']['http_status'] = 500;
    $scopeCountMismatch = $fixture;
    $scopeCountMismatch['scenarios']['document_kind_scope']['distinct_documents'] = 0;
    $missingSuccessfulResponseId = $fixture;
    $missingSuccessfulResponseId['scenarios']['concurrent_same_oid']['successful_response_document_ids'] = 0;
    $missingFinalExpectedBoundary = $fixture;
    $missingFinalExpectedBoundary['scenarios']['concurrent_same_oid']['visibility']['final_exact_matches_expected_ids'] = null;
    $unsafeAccountScope = $fixture;
    $transportEnvelope = [
        'classification' => 'transport_error',
        'transport' => 'exception',
        'exception_class' => RuntimeException::class,
    ];
    $unsafeAccountScope['scenarios']['account_scope']['response_envelopes'] = [
        'primary' => $transportEnvelope,
        'secondary' => $transportEnvelope,
    ];
    $unsafeAccountScope['scenarios']['account_scope']['primary_visibility'] = s03ProbeVisibilityEvidence(0);
    $unsafeAccountScope['scenarios']['account_scope']['secondary_visibility'] = s03ProbeVisibilityEvidence(0);
    $wrongTimeoutKind = $fixture;
    $wrongTimeoutKind['scenarios']['lost_response_after_remote_ack']['transport_failure_kind'] = 'other_transport_failure';
    $looselyTypedResolution = $fixture;
    $looselyTypedResolution['vat_fixture_evidence']['complete'] = 1;
    $wrongAuthorityBinding = $fixture;
    $wrongAuthorityBinding['operator_authorization']['envelope']['consumption']['authority_id'] = 'other-authority';
    $wrongAuthorityPolicy = $fixture;
    $wrongAuthorityPolicy['operator_authorization']['envelope']['consumption']['authority_policy_sha256'] = str_repeat('f', 64);
    $recoveredAuthorityReceipt = $fixture;
    $recoveredAuthorityReceipt['authorization_consumption']['authority_receipt']['envelope']['disposition'] = LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition;
    $conflictingClaimRequest = $fixture;
    $conflictingClaimRequest['authorization_consumption']['authority_receipt']['envelope']['claim_request']['run_id'] = str_repeat('f', 32);
    $missingLaunchManifest = $fixture;
    unset($missingLaunchManifest['launch_manifest_sha256']);
    $conflictingLaunchManifest = $fixture;
    $conflictingLaunchManifest['launch_manifest_sha256'] = str_repeat('f', 64);
    $missingEffectReceipts = $fixture;
    unset($missingEffectReceipts['authorization_consumption']['effect_execution_receipts']);
    $forgedEffectReceipt = $fixture;
    $forgedEffectReceipt['authorization_consumption']['effect_execution_receipts'] = [['receipt' => 'caller-forged']];
    $extraTopLevelField = [...$fixture, 'manual_note' => 'not versioned'];
    $unsafeFixture = s03ProbeFixture('demo_pl', '2026-08-25T10:01:00+00:00', '0002-0002-0002-0002-0002', false);
    $unsafeFixtureWithSafeMatrix = $unsafeFixture;
    $unsafeFixtureWithSafeMatrix['vat_pilot_policy'] = InvoiceIdentityProbe::aggregateEnvironmentEvidence([
        'demo_pl' => $fixture['vat_fixture_evidence'],
        'demo_regional' => s03ProbeFixture('demo_regional', '2026-08-25T10:00:00+00:00', '0003-0003-0003-0003-0003')['vat_fixture_evidence'],
    ]);

    expect(InvoiceIdentityProbe::fixtureEvidenceIsValid($fixture))->toBeTrue()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsSafe($fixture))->toBeTrue()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($withoutPreflights))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($multipleWriteAttempts))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($missingTimeoutEvidence))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($inconsistentResolution))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($classificationEnvelopeMismatch))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($successWithServerErrorStatus))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($scopeCountMismatch))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($missingSuccessfulResponseId))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($missingFinalExpectedBoundary))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($unsafeAccountScope))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($wrongTimeoutKind))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($looselyTypedResolution))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($wrongAuthorityBinding))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($wrongAuthorityPolicy))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($recoveredAuthorityReceipt))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($conflictingClaimRequest))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($missingLaunchManifest))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($conflictingLaunchManifest))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($missingEffectReceipts))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($forgedEffectReceipt))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($extraTopLevelField))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($unsafeFixture))->toBeTrue()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsSafe($unsafeFixture))->toBeFalse()
        ->and(InvoiceIdentityProbe::fixtureEvidenceIsValid($unsafeFixtureWithSafeMatrix))->toBeFalse();
});

it('accepts only a signed post-run result bound to the exact authorization receipt and fixture', function (): void {
    $fixture = s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', str_repeat('a', 32));
    $valid = s03SignedFixturePackage($fixture);
    $validFixture = $valid['fixture'];
    $package = $valid['package'];

    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $validFixture,
        $package['fixture_path'],
        $package['signed_evidence'],
        $valid['signers'],
        $valid['authority_signers'],
    ))->toBeTrue()
        ->and(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
            $validFixture,
            $package['fixture_path'],
            $validFixture['operator_authorization'],
            $valid['signers'],
            $valid['authority_signers'],
        ))->toBeFalse();

    $runStartedAt = new DateTimeImmutable($validFixture['run_started_at']);
    $runFinishedAt = new DateTimeImmutable($validFixture['run_finished_at']);
    expect(fn () => LiveEvidenceAttestationGuard::assertHistoricalEvidenceSignatures(
        $package['signed_evidence'],
        [$validFixture['operator_authorization']],
        $validFixture,
        $runStartedAt,
        $runFinishedAt,
        ProbeConfiguration::MaximumRunDurationSeconds,
        ProbeConfiguration::MaximumAttestationTtlSeconds,
        ProbeConfiguration::MaximumEvidenceAttestationTtlSeconds,
        ProbeConfiguration::MaximumEvidenceSigningDelaySeconds,
        $valid['signers'],
        $valid['authority_signers'],
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LiveEvidenceAttestationGuard::prepareEvidenceEnvelopeForSigning(
            $valid['unsigned_payload'],
            'unit-signer',
            3_600,
        ))->toThrow(InvalidArgumentException::class, 'dual-origin live evidence');

    $crossUsedFixture = $validFixture;
    $crossUsedFixture['operator_authorization'] = $package['signed_evidence'];
    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $crossUsedFixture,
        $package['fixture_path'],
        $package['signed_evidence'],
        $valid['signers'],
        $valid['authority_signers'],
    ))->toBeFalse();

    $mutated = s03SignedFixturePackage(
        $fixture,
        static function (array $envelope): array {
            $envelope['commitments']['configuration_set_sha256'] = str_repeat('f', 64);

            return $envelope;
        },
    );
    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $mutated['fixture'],
        $mutated['package']['fixture_path'],
        $mutated['package']['signed_evidence'],
        $mutated['signers'],
        $mutated['authority_signers'],
    ))->toBeFalse();

    $launchManifestMismatch = s03SignedFixturePackage(
        $fixture,
        static function (array $envelope): array {
            $envelope['run']['launch_manifest_sha256'] = str_repeat('f', 64);

            return $envelope;
        },
    );
    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $launchManifestMismatch['fixture'],
        $launchManifestMismatch['package']['fixture_path'],
        $launchManifestMismatch['package']['signed_evidence'],
        $launchManifestMismatch['signers'],
        $launchManifestMismatch['authority_signers'],
    ))->toBeFalse();

    $tamperedFixture = $validFixture;
    $tamperedFixture['vat_fixture_evidence']['safe'] = false;
    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $tamperedFixture,
        $package['fixture_path'],
        $package['signed_evidence'],
        $valid['signers'],
        $valid['authority_signers'],
    ))->toBeFalse();

    $recoveredFixture = $validFixture;
    $recoveredFixture['authorization_consumption']['authority_receipt']['envelope']['disposition'] = LiveEvidenceAttestationGuard::RecoveredConsumptionDisposition;
    expect(InvoiceIdentityProbe::fixtureEvidenceHasTrustedProvenance(
        $recoveredFixture,
        $package['fixture_path'],
        $package['signed_evidence'],
        $valid['signers'],
        $valid['authority_signers'],
    ))->toBeFalse();
});

it('rejects an invalid fixture before creating any file', function (): void {
    $host = 's03-demo-primary.fakturownia.pl';
    $secondaryHost = 's03-demo-secondary.fakturownia.pl';
    $configuration = new ProbeConfiguration(
        new ProbeEndpoint('demo_pl', "https://{$host}", 'token', ProbeEndpoint::fingerprintFor('demo_pl', $host, '1')),
        new ProbeEndpoint('demo_pl', "https://{$secondaryHost}", 'token', ProbeEndpoint::fingerprintFor('demo_pl', $secondaryHost, '2')),
        s03ProbePayload(),
    );
    $probe = new InvoiceIdentityProbe($configuration);
    $method = new ReflectionMethod(InvoiceIdentityProbe::class, 'writeFixture');
    $mutations = [
        static function (array &$fixture): void {
            $fixture['scenarios']['document_kind_scope']['classifications']['vat'] = 'transport_error';
        },
        static function (array &$fixture): void {
            $fixture['scenarios']['document_kind_scope']['response_envelopes']['vat']['http_status'] = 500;
        },
        static function (array &$fixture): void {
            $fixture['scenarios']['document_kind_scope']['scope'] = 'per_department';
        },
        static function (array &$fixture): void {
            $fixture['scenarios']['document_kind_scope']['distinct_documents'] = 0;
        },
    ];

    foreach ($mutations as $mutate) {
        $runId = bin2hex(random_bytes(16));
        $invalidFixture = s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', $runId);
        $mutate($invalidFixture);
        $path = __DIR__."/../Fixtures/Contract/invoice-identity-demo_pl-{$runId}.json";
        $runStartedAt = new DateTimeImmutable($invalidFixture['run_started_at']);
        $runFinishedAt = new DateTimeImmutable($invalidFixture['run_finished_at']);

        expect($path)->not->toBeFile()
            ->and(fn () => $method->invoke($probe, $invalidFixture, $runId, $runStartedAt, $runFinishedAt))
            ->toThrow(RuntimeException::class, 'structurally invalid')
            ->and($path)->not->toBeFile();
    }
});

it('uses the latest structurally valid evidence including a negative regression', function (): void {
    $authorityKeyPair = sodium_crypto_sign_keypair();
    $authorityKeyMaterial = [
        'secret_key' => sodium_crypto_sign_secretkey($authorityKeyPair),
        'public_key' => sodium_crypto_sign_publickey($authorityKeyPair),
    ];

    try {
        $olderSafePl = s03SignedFixturePackage(
            s03ProbeFixture('demo_pl', '2026-08-25T10:00:00+00:00', str_repeat('1', 32)),
            authorityKeyMaterial: $authorityKeyMaterial,
        );
        $safeRegional = s03SignedFixturePackage(
            s03ProbeFixture('demo_regional', '2026-08-25T10:01:00+00:00', str_repeat('2', 32)),
            authorityKeyMaterial: $authorityKeyMaterial,
        );
        $newerUnsafePl = s03SignedFixturePackage(
            s03ProbeFixture('demo_pl', '2026-08-25T10:02:00+00:00', str_repeat('3', 32), false),
            authorityKeyMaterial: $authorityKeyMaterial,
        );
        $signers = [...$olderSafePl['signers'], ...$safeRegional['signers'], ...$newerUnsafePl['signers']];
        $authoritySigners = $olderSafePl['authority_signers'];

        $safeMatrix = InvoiceIdentityProbe::aggregateEnvironmentFixtures(
            [$olderSafePl['package'], $safeRegional['package']],
            $signers,
            $authoritySigners,
        );
        $regressedMatrix = InvoiceIdentityProbe::aggregateEnvironmentFixtures(
            [$newerUnsafePl['package'], $safeRegional['package'], $olderSafePl['package']],
            $signers,
            $authoritySigners,
        );

        expect($safeMatrix['remote_identity_policy'])->toBe('business_oid')
            ->and($regressedMatrix['remote_identity_policy'])->toBe('no_remote_uniqueness')
            ->and($regressedMatrix['missing_or_unsafe_environments'])->toBe(['demo_pl']);
    } finally {
        sodium_memzero($authorityKeyMaterial['secret_key']);
    }
});
