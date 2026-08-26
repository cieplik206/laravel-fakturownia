<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use SensitiveParameter;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_pad;
use function array_unique;
use function array_values;
use function array_walk_recursive;
use function base64_encode;
use function ceil;
use function dirname;
use function explode;
use function file_get_contents;
use function function_exists;
use function getenv;
use function hash;
use function hash_equals;
use function hash_hmac;
use function in_array;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_link;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_quote;
use function preg_split;
use function random_bytes;
use function realpath;
use function sodium_memzero;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

final class ProbeConfiguration
{
    public const EvidenceContract = 'fakturownia-invoice-identity-s0.3-v1';

    public const MaximumRunDurationSeconds = 21_600;

    public const MaximumAttestationTtlSeconds = self::MaximumRunDurationSeconds;

    public const MaximumConsumptionReceiptTtlSeconds = self::MaximumRunDurationSeconds;

    public const MaximumEvidenceAttestationTtlSeconds = 2_592_000;

    public const MaximumEvidenceSigningDelaySeconds = 86_400;

    public const AuthorizationProfile = 'invoice_identity';

    public const ExactWriteAttemptBudget = 11;

    public const ConsumptionAuthorityId = 's03-external-cas';

    public const ConsumptionStoreId = 's03-invoice-identity';

    public const BrokeredEffectExecutionUnavailable = 'brokered_effect_execution_unavailable';

    public const LiveVisibilityWindowMinimumMs = 10_000;

    public const LiveVisibilityWindowMaximumMs = 120_000;

    public const LivePollIntervalMinimumMs = 100;

    public const LivePollIntervalMaximumMs = 1_000;

    public const LiveMaxSearchPagesMinimum = 10;

    public const LiveMaxSearchPagesMaximum = 100;

    public const LiveLostResponseTimeoutMinimumMs = 1_000;

    public const LiveLostResponseTimeoutMaximumMs = 10_000;

    public const LiveConnectTimeoutMinimumMs = 1_000;

    public const LiveConnectTimeoutMaximumMs = 10_000;

    public const LiveRequestTimeoutMinimumMs = 10_000;

    public const LiveRequestTimeoutMaximumMs = 60_000;

    /** @var list<string> */
    private const ForbiddenPayloadKeys = [
        'api_token',
        'discount',
        'discount_percent',
        'email_to',
        'gov_save_and_send',
        'send_email',
        'send_to_ksef',
    ];

    /** @var array<string, mixed>|null */
    private ?array $authorizationConsumptionReceipt = null;

    private ?VerifiedFreshClaimGrant $verifiedFreshClaimGrant = null;

    private ?ConsumptionClaimRequest $consumptionClaimRequest = null;

    private int $consumedWriteAttempts = 0;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $operatorAuthorization
     * @param  array<string, string>|null  $trustedAttestationSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     */
    public function __construct(
        #[SensitiveParameter] public ProbeEndpoint $primary,
        #[SensitiveParameter] public ProbeEndpoint $secondary,
        #[SensitiveParameter] public array $payload,
        public int $visibilityWindowMs = 10_000,
        public int $pollIntervalMs = 250,
        public int $maxSearchPages = 10,
        public int $lostResponseTimeoutMs = 2_000,
        public int $connectTimeoutMs = 5_000,
        public int $requestTimeoutMs = 30_000,
        #[SensitiveParameter] private ?array $operatorAuthorization = null,
        #[SensitiveParameter]
        private ?string $attestationBindingKey = null,
        #[SensitiveParameter] private ?array $trustedAttestationSigners = null,
        #[SensitiveParameter] private ?array $trustedConsumptionAuthorities = null,
        #[SensitiveParameter] private ?LiveEvidenceConsumptionAuthority $testConsumptionAuthority = null,
        #[SensitiveParameter] private ?ProbeLiteralResponseQueue $testResponseQueue = null,
        private ?string $expectedLaunchManifestSha256 = null,
    ) {
        $templatesValid = ! array_diff(['invoice', 'secondary_account_invoice', 'correction_invoice'], array_keys(array_filter($payload, 'is_array')));
        $safety = $payload['safety'] ?? [];
        $safetyConfirmed = is_array($safety) && ($safety['throwaway_tenants'] ?? false) === true
            && ($safety['ksef_auto_send_disabled'] ?? false) === true
            && ($safety['email_delivery_disabled'] ?? false) === true;
        $primaryDepartment = $payload['invoice']['department_id'] ?? null;
        $secondaryDepartment = $payload['secondary_department_id'] ?? null;

        if (! $templatesValid || ! $safetyConfirmed) {
            throw new InvalidArgumentException('Three invoice templates and all DEMO safety assertions are required.');
        }
        if (! is_scalar($primaryDepartment) || ! is_scalar($secondaryDepartment) || (string) $primaryDepartment === (string) $secondaryDepartment) {
            throw new InvalidArgumentException('Two distinct DEMO department IDs are required.');
        }
        if ($primary->environment !== $secondary->environment || hash_equals($primary->host, $secondary->host)) {
            throw new InvalidArgumentException('Use two different tenants in the same DEMO environment.');
        }
        if ($visibilityWindowMs < 1 || $pollIntervalMs < 1 || $pollIntervalMs > $visibilityWindowMs || $maxSearchPages < 1 || $lostResponseTimeoutMs < 1) {
            throw new InvalidArgumentException('Probe limits must be positive.');
        }
        $usesTestAuthoritySeam = $trustedAttestationSigners !== null
            || $trustedConsumptionAuthorities !== null
            || $testConsumptionAuthority !== null;
        if ($usesTestAuthoritySeam
            && ($trustedAttestationSigners === null
                || $trustedConsumptionAuthorities === null
                || $testConsumptionAuthority === null
                || $testResponseQueue === null
                || $expectedLaunchManifestSha256 !== self::offlineLaunchManifestSha256ForTesting())) {
            throw new InvalidArgumentException('The offline authority seam requires operator keys, distinct authority keys, an authority and a sealed literal response queue.');
        }
        if ($expectedLaunchManifestSha256 !== null
            && preg_match('/^[a-f0-9]{64}$/', $expectedLaunchManifestSha256) !== 1) {
            throw new InvalidArgumentException('The expected launch manifest digest must be canonical SHA-256.');
        }

        foreach (['invoice', 'secondary_account_invoice', 'correction_invoice'] as $template) {
            self::validateTemplate($payload[$template]);
        }

        self::assertPayloadContainsNoForbiddenFields($payload);
    }

    public static function enabled(): bool
    {
        return getenv('FAKTUROWNIA_CONTRACT_PROBE_ENABLED') === 'yes';
    }

    public static function offlineLaunchManifestSha256ForTesting(): string
    {
        return hash('sha256', 's03-offline-literal-launch-manifest-v1');
    }

    /** @return list<string> */
    public function sensitivePayloadValues(): array
    {
        $values = [];
        $collect = static function (mixed $value, string|int $key) use (&$values): void {
            if (! is_string($key) || ! preg_match('/(?:buyer|seller|name|tax_(?:no|id)|nip|vat_(?:no|id)|address|street|city|post|phone|email|note)/i', $key) || ! is_scalar($value) || is_bool($value)) {
                return;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                $values[] = $string;
            }
        };
        array_walk_recursive($this->payload, $collect);

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $signedAuthorization
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     */
    public function withOperatorAuthorization(
        #[SensitiveParameter] array $signedAuthorization,
        #[SensitiveParameter] string $bindingKey,
        #[SensitiveParameter] ?array $trustedSigners = null,
        #[SensitiveParameter] ?array $trustedConsumptionAuthorities = null,
        #[SensitiveParameter] ?LiveEvidenceConsumptionAuthority $consumptionAuthority = null,
        #[SensitiveParameter] ?ProbeLiteralResponseQueue $responseQueue = null,
    ): self {
        return new self(
            $this->primary,
            $this->secondary,
            $this->payload,
            $this->visibilityWindowMs,
            $this->pollIntervalMs,
            $this->maxSearchPages,
            $this->lostResponseTimeoutMs,
            $this->connectTimeoutMs,
            $this->requestTimeoutMs,
            $signedAuthorization,
            $bindingKey,
            $trustedSigners,
            $trustedConsumptionAuthorities,
            $consumptionAuthority,
            $responseQueue,
            $trustedSigners === null ? null : self::offlineLaunchManifestSha256ForTesting(),
        );
    }

    public function withLiteralResponseQueueForTesting(
        #[SensitiveParameter] ProbeLiteralResponseQueue $responseQueue,
    ): self {
        return new self(
            $this->primary,
            $this->secondary,
            $this->payload,
            $this->visibilityWindowMs,
            $this->pollIntervalMs,
            $this->maxSearchPages,
            $this->lostResponseTimeoutMs,
            $this->connectTimeoutMs,
            $this->requestTimeoutMs,
            $this->operatorAuthorization,
            $this->attestationBindingKey,
            $this->trustedAttestationSigners,
            $this->trustedConsumptionAuthorities,
            $this->testConsumptionAuthority,
            $responseQueue,
            $this->expectedLaunchManifestSha256,
        );
    }

    public function claimOperatorAuthorization(DateTimeImmutable $now): VerifiedFreshClaimGrant
    {
        if ($this->verifiedFreshClaimGrant !== null) {
            throw new RuntimeException('This S0.3 authorization has already been claimed for one run.');
        }

        $signedAuthorization = $this->assertTrustedOperatorAuthorization($now);

        if ($this->trustedAttestationSigners === null) {
            throw self::brokeredExecutionUnavailable();
        }

        $this->assertSignatureOnlySeamUsesLiteralTransport();
        $authority = $this->testConsumptionAuthority
            ?? throw new RuntimeException('The offline S0.3 authority seam is unavailable.');
        $trustedConsumptionAuthorities = $this->trustedConsumptionAuthorities
            ?? throw new RuntimeException('The offline S0.3 authority keys are unavailable.');
        $grant = LiveEvidenceAttestationGuard::claimAuthorizationSignaturesWithAuthorityNow(
            [$signedAuthorization],
            $now,
            $now,
            self::MaximumAttestationTtlSeconds,
            self::MaximumConsumptionReceiptTtlSeconds,
            $authority,
            base64_encode(random_bytes(32)),
            $this->trustedAttestationSigners,
            $trustedConsumptionAuthorities,
        );
        $claimRequest = $grant->grant->receipt->envelope->claimRequest;
        $localClaim = LiveEvidenceAttestationGuard::buildConsumptionReceipt([$signedAuthorization], $now);

        $this->verifiedFreshClaimGrant = $grant;
        $this->consumptionClaimRequest = $claimRequest;
        $this->authorizationConsumptionReceipt = [
            'local_claim' => $localClaim,
            'authority_receipt' => $grant->toArray(),
            'effect_execution_receipts' => [],
        ];

        return $grant;
    }

    /** @return array<string, mixed> */
    public function authorizationConsumptionReceipt(): array
    {
        if ($this->authorizationConsumptionReceipt === null) {
            throw new RuntimeException('The signed operator authorization must be atomically consumed before an invoice write.');
        }

        return $this->authorizationConsumptionReceipt;
    }

    public function consumptionClaimRequest(): ConsumptionClaimRequest
    {
        return $this->consumptionClaimRequest
            ?? throw new RuntimeException('The external authority claim request is unavailable before a fresh direct grant.');
    }

    public function verifiedFreshClaimGrant(): VerifiedFreshClaimGrant
    {
        return $this->verifiedFreshClaimGrant
            ?? throw new RuntimeException('The verified fresh external authority grant is unavailable.');
    }

    public function usesOfflineTestAuthoritySeam(): bool
    {
        return $this->trustedAttestationSigners !== null;
    }

    public function assertProviderRunAvailable(): void
    {
        if ($this->usesOfflineTestAuthoritySeam()) {
            $this->assertSignatureOnlySeamUsesLiteralTransport();

            return;
        }

        throw self::brokeredExecutionUnavailable();
    }

    public function assertRealProviderTransportOrigin(): void
    {
        if ($this->usesOfflineTestAuthoritySeam()
            || $this->trustedConsumptionAuthorities !== null
            || $this->testConsumptionAuthority !== null
            || $this->testResponseQueue !== null
            || ! self::enabled()) {
            throw new RuntimeException('Canonical live evidence requires the enabled environment-only probe with real provider transport and pinned trust stores.');
        }

        self::assertLiveLimits(
            $this->visibilityWindowMs,
            $this->pollIntervalMs,
            $this->maxSearchPages,
            $this->lostResponseTimeoutMs,
            $this->connectTimeoutMs,
            $this->requestTimeoutMs,
        );

        self::assertProviderRuntimeIsolated();

        throw self::brokeredExecutionUnavailable();
    }

    /** @return array<string, mixed> */
    public function assertTrustedOperatorAuthorization(DateTimeImmutable $now): array
    {
        if ($this->operatorAuthorization === null || $this->attestationBindingKey === null) {
            throw new RuntimeException('A signed operator attestation and non-persisted binding key are required before live HTTP.');
        }

        if ($this->trustedAttestationSigners !== null) {
            $this->assertSignatureOnlySeamUsesLiteralTransport();
        }

        if ($this->trustedAttestationSigners === null) {
            self::assertLiveLimits(
                $this->visibilityWindowMs,
                $this->pollIntervalMs,
                $this->maxSearchPages,
                $this->lostResponseTimeoutMs,
                $this->connectTimeoutMs,
                $this->requestTimeoutMs,
            );
        }
        $envelope = $this->trustedAttestationSigners === null
            ? LiveEvidenceAttestationGuard::assertAuthorizedNow(
                $this->operatorAuthorization,
                dirname(__DIR__, 3),
                $now,
                self::MaximumAttestationTtlSeconds,
            )
            : LiveEvidenceAttestationGuard::assertAuthorizationSignature(
                $this->operatorAuthorization,
                $now,
                self::MaximumAttestationTtlSeconds,
                $this->trustedAttestationSigners,
            );
        $this->assertExactAuthorizationDomain($envelope);

        $issuedAt = self::strictAuthorizationDate($envelope['issued_at'] ?? null);
        if ($issuedAt > $now) {
            throw new RuntimeException('The S0.3 operator authorization cannot begin after the live run starts.');
        }

        return $this->operatorAuthorization;
    }

    /** @return array<string, mixed> */
    public function assertOperatorAuthorizationCoversRun(
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
    ): array {
        $runDurationMicroseconds = self::instantMicroseconds($runFinishedAt) - self::instantMicroseconds($runStartedAt);
        if ($runDurationMicroseconds < 1_000_000
            || $runDurationMicroseconds > self::MaximumRunDurationSeconds * 1_000_000) {
            throw new RuntimeException('The S0.3 run duration is outside the frozen safe range.');
        }

        $envelope = $this->trustedAttestationSigners === null
            ? LiveEvidenceAttestationGuard::assertHistoricalAuthorization(
                $this->operatorAuthorization ?? [],
                dirname(__DIR__, 3),
                $runStartedAt,
                $runFinishedAt,
                self::MaximumAttestationTtlSeconds,
            )
            : LiveEvidenceAttestationGuard::assertHistoricalAuthorizationSignature(
                $this->operatorAuthorization ?? [],
                $runStartedAt,
                $runFinishedAt,
                self::MaximumAttestationTtlSeconds,
                $this->trustedAttestationSigners,
            );
        $this->assertExactAuthorizationDomain($envelope);

        $issuedAt = self::strictAuthorizationDate($envelope['issued_at'] ?? null);
        $expiresAt = self::strictAuthorizationDate($envelope['expires_at'] ?? null);
        if ($runStartedAt < $issuedAt || $runFinishedAt >= $expiresAt) {
            throw new RuntimeException('The S0.3 run is not fully contained by its pre-run authorization window.');
        }

        return $envelope;
    }

    public function assertEffectAuthorizedNow(int $writeAttempts = 1): void
    {
        if ($writeAttempts < 1 || $this->consumedWriteAttempts + $writeAttempts > self::ExactWriteAttemptBudget) {
            throw new RuntimeException('The signed S0.3 write-attempt budget would be exceeded.');
        }

        $grant = $this->verifiedFreshClaimGrant
            ?? throw new RuntimeException('A verified fresh external authority grant is required before an invoice write.');
        $claimRequest = $this->consumptionClaimRequest();
        $signedAuthorization = $this->operatorAuthorization
            ?? throw new RuntimeException('The signed S0.3 authorization is unavailable at the effect boundary.');
        $trustedOperatorSigners = $this->trustedAttestationSigners
            ?? throw new RuntimeException('The live remote authority boundary is not available in the offline S0.3 contract probe.');
        $trustedConsumptionAuthorities = $this->trustedConsumptionAuthorities
            ?? throw new RuntimeException('The offline S0.3 authority keys are unavailable.');
        $minimumRemainingSeconds = (int) ceil($this->requestTimeoutMs / 1_000);

        for ($attempt = 0; $attempt < $writeAttempts; $attempt++) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->assertTrustedOperatorAuthorization($now);
            LiveEvidenceAttestationGuard::assertVerifiedFreshGrantSignaturesAtEffectBoundary(
                $grant,
                [$signedAuthorization],
                $claimRequest,
                $now,
                self::MaximumAttestationTtlSeconds,
                $minimumRemainingSeconds,
                self::MaximumConsumptionReceiptTtlSeconds,
                $trustedOperatorSigners,
                $trustedConsumptionAuthorities,
            );
            $this->consumedWriteAttempts++;
        }
    }

    public function assertExactWriteBudgetConsumed(): void
    {
        if ($this->consumedWriteAttempts !== self::ExactWriteAttemptBudget) {
            throw new RuntimeException('The S0.3 run did not consume its exact signed write-attempt budget.');
        }
    }

    public function consumedWriteAttempts(): int
    {
        return $this->consumedWriteAttempts;
    }

    public function destroyBindingKey(): void
    {
        if ($this->attestationBindingKey === null) {
            return;
        }

        if (function_exists('sodium_memzero')) {
            sodium_memzero($this->attestationBindingKey);
        }
        $this->attestationBindingKey = null;
    }

    public function bindTestTransport(#[SensitiveParameter] FakturowniaProbeConnector $connector): FakturowniaProbeConnector
    {
        if ($this->testResponseQueue === null) {
            return $connector;
        }

        return $connector->withLiteralResponseQueue($this->testResponseQueue);
    }

    /** @return array<string, mixed> */
    public function authorizationDomain(
        #[SensitiveParameter] string $bindingKey,
        ?string $testLaunchManifestSha256 = null,
    ): array {
        if (strlen($bindingKey) < 32) {
            throw new InvalidArgumentException('The non-persisted attestation binding key must contain at least 32 random bytes.');
        }

        $launchManifestSha256 = $testLaunchManifestSha256 ?? $this->expectedLaunchManifestSha256;
        if (! is_string($launchManifestSha256)) {
            throw new RuntimeException('An independently verified launch manifest digest is required.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $launchManifestSha256) !== 1) {
            throw new InvalidArgumentException('The supervised launch manifest digest must be canonical SHA-256.');
        }

        return [
            'evidence_contract' => self::EvidenceContract,
            'challenge' => self::authorizationChallenge($bindingKey),
            'harness' => [
                'code_sha256' => self::harnessCodeSha256(),
                'repository_commit' => self::repositoryCommit(),
                'launch_manifest_sha256' => $launchManifestSha256,
            ],
            'target' => [
                'environment' => $this->primary->environment,
                'profile' => self::AuthorizationProfile,
                'tenant_hmac_sha256' => $this->evidenceCommitmentsFor($bindingKey)['tenant_hmac_sha256'],
                'account_hmac_sha256' => $this->evidenceCommitmentsFor($bindingKey)['account_hmac_sha256'],
            ],
            'commitments' => $this->authorizationCommitmentsFor($bindingKey),
            'consumption' => [
                'authority_id' => self::ConsumptionAuthorityId,
                'authority_policy_sha256' => hash('sha256', 's03-external-atomic-cas-policy-v1'),
                'store_id' => self::ConsumptionStoreId,
                'store_identity_sha256' => hash('sha256', 's03-external-atomic-cas-store-v1'),
                'run_id' => substr(hash_hmac('sha256', 's0.3-authorization-run', $bindingKey), 0, 32),
                'replay_policy' => LiveEvidenceAttestationGuard::ConsumptionReplayPolicy,
            ],
            'limits' => $this->probeLimits(),
        ];
    }

    /** @return array<string, mixed> */
    public function evidenceCommitments(): array
    {
        if ($this->attestationBindingKey === null) {
            throw new RuntimeException('The non-persisted binding key is required to produce blinded evidence commitments.');
        }

        return $this->evidenceCommitmentsFor($this->attestationBindingKey);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function buildUnsignedEvidencePayload(
        #[SensitiveParameter] string $fixturePath,
        #[SensitiveParameter] string $fixtureSha256,
        #[SensitiveParameter] array $fixture,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        ?VerifiedLiveProviderRun $providerRun = null,
    ): array {
        $authorization = $this->assertOperatorAuthorizationCoversRun($runStartedAt, $runFinishedAt);
        $archivedHarness = LiveEvidenceAttestationGuard::harnessSnapshot(dirname(__DIR__, 3), self::EvidenceContract);

        $authorizations = [[
            'profile' => self::AuthorizationProfile,
            'challenge' => (string) $authorization['challenge'],
            'sha256' => LiveEvidenceAttestationGuard::signedDocumentSha256($this->operatorAuthorization ?? []),
        ]];
        $commitments = LiveEvidenceAttestationGuard::evidenceCommitments(
            [$this->operatorAuthorization ?? []],
            $fixture,
            self::EvidenceContract,
        );

        if ($providerRun !== null) {
            $localClaim = $this->authorizationConsumptionReceipt()['local_claim'] ?? null;
            if (! is_array($localClaim)) {
                throw new RuntimeException('Canonical live evidence requires its exact local claim observation.');
            }

            return LiveEvidenceAttestationGuard::buildLiveUnsignedEvidencePayload(
                self::EvidenceContract,
                $fixturePath,
                $fixtureSha256,
                self::repositoryCommit(),
                hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($archivedHarness)),
                $archivedHarness,
                $this->verifiedFreshClaimGrant(),
                $providerRun,
                [$this->operatorAuthorization ?? []],
                $localClaim,
                $authorizations,
                $commitments,
            );
        }

        return LiveEvidenceAttestationGuard::buildUnsignedEvidencePayload(
            self::EvidenceContract,
            $fixturePath,
            $fixtureSha256,
            self::repositoryCommit(),
            hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($archivedHarness)),
            $this->authorizationLaunchManifestSha256(),
            $archivedHarness,
            $runStartedAt,
            $runFinishedAt,
            $this->primary->environment,
            $this->authorizationConsumptionReceipt(),
            $authorizations,
            $commitments,
        );
    }

    public function authorizationLaunchManifestSha256(): string
    {
        $launchManifestSha256 = $this->expectedLaunchManifestSha256;
        $signedLaunchManifestSha256 = $this->operatorAuthorization['envelope']['harness']['launch_manifest_sha256'] ?? null;

        if (! is_string($launchManifestSha256)
            || ! is_string($signedLaunchManifestSha256)
            || preg_match('/^[a-f0-9]{64}$/', $launchManifestSha256) !== 1
            || ! hash_equals($launchManifestSha256, $signedLaunchManifestSha256)) {
            throw new RuntimeException('The signed authorization does not match the independently supplied launch manifest digest.');
        }

        return $launchManifestSha256;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{unsigned_path: string, authorization_paths: list<string>}
     */
    public function publishUnsignedEvidenceSidecar(
        #[SensitiveParameter] array $payload,
        ?VerifiedLiveProviderRun $providerRun = null,
    ): array {
        if ($this->operatorAuthorization === null) {
            throw new RuntimeException('The signed pre-run authorization is required to publish the unsigned evidence sidecar.');
        }

        if ($providerRun !== null) {
            return LiveEvidenceAttestationGuard::writeLiveUnsignedEvidenceSidecar(
                dirname(__DIR__, 3),
                $payload,
                [$this->operatorAuthorization],
                $this->verifiedFreshClaimGrant(),
                $providerRun,
                self::MaximumAttestationTtlSeconds,
            );
        }

        return LiveEvidenceAttestationGuard::writeUnsignedEvidenceSidecar(
            dirname(__DIR__, 3),
            $payload,
            [$this->operatorAuthorization],
            self::MaximumAttestationTtlSeconds,
            $this->trustedAttestationSigners,
            $this->trustedConsumptionAuthorities,
        );
    }

    /** @return array{visibility_window_ms: int, poll_interval_ms: int, max_search_pages: int, lost_response_timeout_ms: int, connect_timeout_ms: int, request_timeout_ms: int, write_attempt_budget: int} */
    public function probeLimits(): array
    {
        return [
            'visibility_window_ms' => $this->visibilityWindowMs,
            'poll_interval_ms' => $this->pollIntervalMs,
            'max_search_pages' => $this->maxSearchPages,
            'lost_response_timeout_ms' => $this->lostResponseTimeoutMs,
            'connect_timeout_ms' => $this->connectTimeoutMs,
            'request_timeout_ms' => $this->requestTimeoutMs,
            'write_attempt_budget' => self::ExactWriteAttemptBudget,
        ];
    }

    public static function assertLiveLimits(
        int $visibilityWindowMs,
        int $pollIntervalMs,
        int $maxSearchPages,
        int $lostResponseTimeoutMs,
        int $connectTimeoutMs,
        int $requestTimeoutMs,
    ): void {
        if ($visibilityWindowMs < self::LiveVisibilityWindowMinimumMs
            || $visibilityWindowMs > self::LiveVisibilityWindowMaximumMs
            || $pollIntervalMs < self::LivePollIntervalMinimumMs
            || $pollIntervalMs > self::LivePollIntervalMaximumMs
            || $maxSearchPages < self::LiveMaxSearchPagesMinimum
            || $maxSearchPages > self::LiveMaxSearchPagesMaximum
            || $lostResponseTimeoutMs < self::LiveLostResponseTimeoutMinimumMs
            || $lostResponseTimeoutMs > self::LiveLostResponseTimeoutMaximumMs
            || $connectTimeoutMs < self::LiveConnectTimeoutMinimumMs
            || $connectTimeoutMs > self::LiveConnectTimeoutMaximumMs
            || $requestTimeoutMs < self::LiveRequestTimeoutMinimumMs
            || $requestTimeoutMs > self::LiveRequestTimeoutMaximumMs) {
            throw new InvalidArgumentException('Live probe limits fall outside the frozen safe range.');
        }
    }

    public static function harnessCodeSha256(): string
    {
        return LiveEvidenceAttestationGuard::harnessCodeSha256(dirname(__DIR__, 3), self::EvidenceContract);
    }

    public static function repositoryCommit(): string
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $gitDirectory = self::resolveGitDirectory($repositoryRoot);
        $head = self::readTrimmedFile($gitDirectory.'/HEAD');

        if (preg_match('/^[a-f0-9]{40}$/', $head) === 1) {
            return $head;
        }

        if (! str_starts_with($head, 'ref: ')) {
            throw new RuntimeException('The SDK repository HEAD cannot be resolved.');
        }

        $reference = substr($head, 5);
        if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $reference) !== 1 || str_contains($reference, '..')) {
            throw new RuntimeException('The SDK repository HEAD reference is invalid.');
        }

        $looseReference = $gitDirectory.'/'.$reference;
        if (is_file($looseReference)) {
            $commit = self::readTrimmedFile($looseReference);

            if (preg_match('/^[a-f0-9]{40}$/', $commit) === 1) {
                return $commit;
            }
        }

        $packedReferences = self::readTrimmedFile($gitDirectory.'/packed-refs');
        foreach (preg_split('/\R/', $packedReferences) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }

            [$commit, $packedReference] = array_pad(explode(' ', $line, 2), 2, null);
            if ($packedReference === $reference && is_string($commit) && preg_match('/^[a-f0-9]{40}$/', $commit) === 1) {
                return $commit;
            }
        }

        throw new RuntimeException('The SDK repository commit cannot be resolved.');
    }

    public static function fromEnvironment(): never
    {
        self::assertProviderRuntimeIsolated();

        throw self::brokeredExecutionUnavailable();
    }

    /** @param array<array-key, mixed> $payload */
    public static function assertPayloadContainsNoForbiddenFields(#[SensitiveParameter] array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalizedKey = strtolower($key);
                $forbidden = in_array($normalizedKey, self::ForbiddenPayloadKeys, true)
                    || str_ends_with($normalizedKey, '_email');

                if ($forbidden) {
                    throw new InvalidArgumentException("Field {$key} is forbidden in a contract probe payload.");
                }
            }

            if (is_array($value)) {
                self::assertPayloadContainsNoForbiddenFields($value);

                continue;
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Objects and resources are forbidden in a contract probe payload.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }

    private static function hmacSha256(#[SensitiveParameter] mixed $value, #[SensitiveParameter] string $bindingKey): string
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Attested HMAC inputs must be JSON objects.');
        }

        return hash_hmac('sha256', LiveEvidenceAttestationGuard::canonicalJson($value), $bindingKey);
    }

    private static function authorizationChallenge(#[SensitiveParameter] string $bindingKey): string
    {
        return base64_encode(hash_hmac('sha256', 's0.3-authorization-challenge', $bindingKey, true));
    }

    /** @return array<string, mixed> */
    private function evidenceCommitmentsFor(#[SensitiveParameter] string $bindingKey): array
    {
        $policy = [
            'identity_field' => 'oid',
            'remote_uniqueness_flag' => 'oid_unique=yes',
            'lost_response_blind_retry' => false,
            'visibility' => 'full_window_with_final_exact_boundary',
            'required_environment_fixtures' => ['demo_pl', 'demo_regional'],
        ];
        $tenant = [
            'primary' => ['environment' => $this->primary->environment, 'base_url' => $this->primary->baseUrl],
            'secondary' => ['environment' => $this->secondary->environment, 'base_url' => $this->secondary->baseUrl],
        ];
        $accounts = [
            'identity_basis' => 'environment_account_id',
            'primary_account_fingerprint' => $this->primary->expectedFingerprint,
            'secondary_account_fingerprint' => $this->secondary->expectedFingerprint,
        ];
        $profile = [
            'primary_token' => $this->primary->token,
            'secondary_token' => $this->secondary->token,
            'payload' => $this->payload,
            'limits' => $this->probeLimits(),
        ];
        $templates = [
            'invoice' => $this->payload['invoice'],
            'secondary_account_invoice' => $this->payload['secondary_account_invoice'],
            'correction_invoice' => $this->payload['correction_invoice'],
        ];

        return [
            'scheme' => LiveEvidenceAttestationGuard::CommitmentScheme,
            'tenant_hmac_sha256' => self::hmacSha256($tenant, $bindingKey),
            'account_hmac_sha256' => self::hmacSha256($accounts, $bindingKey),
            'profile_hmac_sha256' => self::hmacSha256($profile, $bindingKey),
            'policy_hmac_sha256' => self::hmacSha256($policy, $bindingKey),
            'safety_hmac_sha256' => self::hmacSha256($this->payload['safety'], $bindingKey),
            'templates_hmac_sha256' => self::hmacSha256($templates, $bindingKey),
        ];
    }

    /** @return array<string, mixed> */
    private function authorizationCommitmentsFor(#[SensitiveParameter] string $bindingKey): array
    {
        $commitments = $this->evidenceCommitmentsFor($bindingKey);

        return [
            'scheme' => $commitments['scheme'],
            'configuration_hmac_sha256' => $commitments['profile_hmac_sha256'],
            'policy_hmac_sha256' => $commitments['policy_hmac_sha256'],
            'safety_hmac_sha256' => $commitments['safety_hmac_sha256'],
            'templates_hmac_sha256' => $commitments['templates_hmac_sha256'],
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function assertExactAuthorizationDomain(#[SensitiveParameter] array $envelope): void
    {
        if ($this->attestationBindingKey === null) {
            throw new RuntimeException('The non-persisted binding key is required to verify the S0.3 authorization.');
        }

        $expectedDomain = $this->authorizationDomain($this->attestationBindingKey);
        $domainKeys = ['evidence_contract', 'challenge', 'harness', 'target', 'commitments', 'consumption', 'limits'];
        $actualDomain = array_intersect_key($envelope, array_fill_keys($domainKeys, true));
        $expectedEnvelopeKeys = ['contract', 'version', 'algorithm', 'signer_id', 'issued_at', 'expires_at', ...$domainKeys];

        if (! self::hasExactKeys($envelope, $expectedEnvelopeKeys)
            || ! hash_equals(
                LiveEvidenceAttestationGuard::canonicalJson($expectedDomain),
                LiveEvidenceAttestationGuard::canonicalJson($actualDomain),
            )) {
            throw new RuntimeException('The signed operator attestation does not bind the exact S0.3 probe configuration.');
        }

    }

    private static function strictAuthorizationDate(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
            throw new RuntimeException('The operator authorization timestamp is not canonical UTC RFC3339.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('The operator authorization timestamp is not a valid UTC instant.');
        }

        return $date;
    }

    private static function instantMicroseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U') * 1_000_000) + (int) $date->format('u');
    }

    private static function resolveGitDirectory(string $repositoryRoot): string
    {
        $dotGit = $repositoryRoot.'/.git';
        if (is_dir($dotGit)) {
            $resolved = realpath($dotGit);

            if (is_string($resolved)) {
                return $resolved;
            }
        }

        if (! is_file($dotGit) || is_link($dotGit)) {
            throw new RuntimeException('The SDK Git directory cannot be resolved.');
        }

        $pointer = self::readTrimmedFile($dotGit);
        if (! str_starts_with($pointer, 'gitdir: ')) {
            throw new RuntimeException('The SDK Git directory pointer is invalid.');
        }

        $path = substr($pointer, 8);
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $repositoryRoot.'/'.$path;
        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('The SDK Git directory pointer cannot be resolved.');
        }

        return $resolved;
    }

    private static function readTrimmedFile(string $path): string
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('A required SDK Git metadata file is missing.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('A required SDK Git metadata file cannot be read.');
        }

        return trim($contents);
    }

    /** @param array<string, mixed> $template */
    private static function validateTemplate(#[SensitiveParameter] array $template): void
    {
        $required = ['department_id', 'issue_date', 'sell_date', 'payment_to_kind', 'buyer_name', 'buyer_tax_no', 'currency', 'positions'];
        if (array_diff($required, array_keys($template)) !== [] || ! is_array($template['positions']) || $template['positions'] === []) {
            throw new InvalidArgumentException('Every template must contain the complete stable business fingerprint fields.');
        }

        foreach ($template['positions'] as $position) {
            if (! is_array($position) || array_diff(['name', 'quantity', 'price_net', 'tax'], array_keys($position)) !== []) {
                throw new InvalidArgumentException('Every position must contain name, quantity, price_net and tax.');
            }
        }
    }

    private function assertSignatureOnlySeamUsesLiteralTransport(): void
    {
        $this->signatureOnlySeamResponseQueue();
    }

    private function signatureOnlySeamResponseQueue(): ProbeLiteralResponseQueue
    {
        return $this->testResponseQueue
            ?? throw new RuntimeException('Explicit signer seams require their sealed literal response queue and cannot authorize real HTTP.');
    }

    public static function assertProviderRuntimeIsolated(): void
    {
        if (MockClient::getGlobal() !== null) {
            throw new RuntimeException('The S0.3 probe refuses mutable global Saloon mock state.');
        }

        $globalMiddleware = Config::globalMiddleware();
        if ($globalMiddleware->getRequestPipeline()->getPipes() !== []
            || $globalMiddleware->getResponsePipeline()->getPipes() !== []
            || $globalMiddleware->getFatalPipeline()->getPipes() !== []) {
            throw new RuntimeException('The S0.3 probe refuses mutable global Saloon middleware.');
        }
    }

    private static function brokeredExecutionUnavailable(): RuntimeException
    {
        return new RuntimeException(self::BrokeredEffectExecutionUnavailable.': native supervisor launch manifest and brokered effect execution are unavailable.');
    }
}

final class ProbeEndpoint
{
    public string $baseUrl;

    public string $host;

    public function __construct(
        public string $environment,
        #[SensitiveParameter] string $baseUrl,
        #[SensitiveParameter] public string $token,
        #[SensitiveParameter] public string $expectedFingerprint,
    ) {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $forbiddenComponents = ['user', 'pass', 'port', 'query', 'fragment'];
        $hasForbiddenComponent = false;
        foreach ($forbiddenComponents as $component) {
            if (is_array($parts) && array_key_exists($component, $parts)) {
                $hasForbiddenComponent = true;

                break;
            }
        }
        $plainHttps = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($path, ['', '/'], true)
            && ! $hasForbiddenComponent;
        $expectedDomain = match ($environment) {
            'demo_pl' => 'fakturownia.pl',
            'demo_regional' => 'invoiceocean.com',
            default => null,
        };

        if (! $plainHttps || $expectedDomain === null) {
            throw new InvalidArgumentException('Use a plain HTTPS URL and explicit DEMO environment.');
        }
        if (! preg_match('/^s03-demo-[a-z0-9-]+\.'.preg_quote($expectedDomain, '/').'$/', $host)) {
            throw new InvalidArgumentException('Use an approved s03-demo-* throwaway tenant.');
        }
        if ($token === '' || ! preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)) {
            throw new InvalidArgumentException('Confirm the exact DEMO tenant fingerprint and token.');
        }

        $this->baseUrl = 'https://'.$host;
        $this->host = $host;
    }

    public static function fingerprintFor(
        string $environment,
        #[SensitiveParameter] string $host,
        #[SensitiveParameter] string $accountId,
    ): string {
        self::assertCanonicalAccountId($accountId);

        return hash('sha256', "fakturownia-s0.3|{$environment}|".strtolower($host)."|{$accountId}");
    }

    public static function accountIdentityFor(string $environment, #[SensitiveParameter] string $accountId): string
    {
        self::assertCanonicalAccountId($accountId);

        return hash('sha256', "fakturownia-s0.3-account|{$environment}|{$accountId}");
    }

    public function verifyAccountId(#[SensitiveParameter] string $accountId): string
    {
        $fingerprint = self::fingerprintFor($this->environment, $this->host, $accountId);
        if (! hash_equals($this->expectedFingerprint, $fingerprint)) {
            throw new RuntimeException('The API token does not match the allowlisted throwaway DEMO account.');
        }

        return self::accountIdentityFor($this->environment, $accountId);
    }

    private static function assertCanonicalAccountId(#[SensitiveParameter] string $accountId): void
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $accountId) !== 1) {
            throw new InvalidArgumentException('The DEMO account ID must be a canonical positive decimal identifier.');
        }
    }
}
