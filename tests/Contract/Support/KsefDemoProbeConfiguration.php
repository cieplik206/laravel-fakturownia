<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerSession;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\VerifiedLaunchManifest;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function array_walk_recursive;
use function clearstatcache;
use function count;
use function dirname;
use function fclose;
use function fopen;
use function fstat;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_link;
use function is_resource;
use function is_scalar;
use function is_string;
use function ksort;
use function lstat;
use function posix_geteuid;
use function preg_match;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function trim;

enum KsefOwnership: string
{
    case ExplicitSdk = 'explicit_sdk';
    case ProviderAutoSend = 'provider_auto_send';
}

enum KsefValidationMode: string
{
    case BlockInvalid = 'block_invalid';
    case PersistWithErrors = 'persist_with_errors';
}

final class KsefDemoProbeConfiguration
{
    public const EvidenceContract = 'fakturownia-ksef-demo-s0.4-v1';

    public const DefaultPollWindowMs = 30_000;

    public const DefaultPollIntervalMs = 500;

    public const DefaultMaxSearchPages = 10;

    public const DefaultPreSendObservationWindowMs = 1_000;

    public const DefaultVisibilityWindowMs = 10_000;

    public const DefaultVisibilityPollIntervalMs = 250;

    public const DefaultConnectTimeoutMs = 5_000;

    public const DefaultRequestTimeoutMs = 30_000;

    public const DefaultMinimumPdfSizeBytes = 1_024;

    public const MaximumRunDurationSeconds = 21_600;

    public const MaximumAuthorizationTtlSeconds = 86_400;

    public const MaximumEvidenceAttestationTtlSeconds = 2_592_000;

    public const MaximumEvidenceSigningDelaySeconds = 86_400;

    private const MaximumPollWindowMs = 120_000;

    private const MinimumPollIntervalMs = 100;

    private const MaximumSearchPages = 50;

    private const MaximumPreSendObservationWindowMs = 30_000;

    private const MaximumVisibilityWindowMs = 60_000;

    private const MinimumVisibilityPollIntervalMs = 100;

    private const MaximumConnectTimeoutMs = 10_000;

    private const MaximumRequestTimeoutMs = 60_000;

    private const MaximumPdfSizeBytes = 25 * 1024 * 1024;

    /** @var array<string, array{ownership: KsefOwnership, validation: KsefValidationMode}> */
    private const ExpectedMatrix = [
        'explicit_block' => ['ownership' => KsefOwnership::ExplicitSdk, 'validation' => KsefValidationMode::BlockInvalid],
        'explicit_persist' => ['ownership' => KsefOwnership::ExplicitSdk, 'validation' => KsefValidationMode::PersistWithErrors],
        'auto_block' => ['ownership' => KsefOwnership::ProviderAutoSend, 'validation' => KsefValidationMode::BlockInvalid],
        'auto_persist' => ['ownership' => KsefOwnership::ProviderAutoSend, 'validation' => KsefValidationMode::PersistWithErrors],
    ];

    /** @param array<string, KsefDemoProfile> $profiles */
    public function __construct(
        #[SensitiveParameter] public array $profiles,
        public int $pollWindowMs = self::DefaultPollWindowMs,
        public int $pollIntervalMs = self::DefaultPollIntervalMs,
        public int $maxSearchPages = self::DefaultMaxSearchPages,
        public int $preSendObservationWindowMs = self::DefaultPreSendObservationWindowMs,
        public int $visibilityWindowMs = self::DefaultVisibilityWindowMs,
        public int $visibilityPollIntervalMs = self::DefaultVisibilityPollIntervalMs,
        public int $connectTimeoutMs = self::DefaultConnectTimeoutMs,
        public int $requestTimeoutMs = self::DefaultRequestTimeoutMs,
        public int $minimumPdfSizeBytes = self::DefaultMinimumPdfSizeBytes,
        #[SensitiveParameter] private ?NativeBrokerSaloonSender $nativeBrokerSender = null,
    ) {
        if (array_keys($profiles) !== array_keys(self::ExpectedMatrix)) {
            throw new InvalidArgumentException('The complete ordered four-profile KSeF DEMO matrix is required.');
        }

        if ($pollWindowMs < 1
            || $pollIntervalMs < 1
            || $maxSearchPages < 1
            || $preSendObservationWindowMs < 1
            || $visibilityWindowMs < 1
            || $visibilityPollIntervalMs < 1
            || $connectTimeoutMs < 1
            || $requestTimeoutMs < 1
            || $minimumPdfSizeBytes < 1
            || $minimumPdfSizeBytes > self::MaximumPdfSizeBytes) {
            throw new InvalidArgumentException('Probe limits must be positive.');
        }

        if ($pollIntervalMs > $pollWindowMs
            || $pollIntervalMs > $preSendObservationWindowMs
            || $visibilityPollIntervalMs > $visibilityWindowMs) {
            throw new InvalidArgumentException('Every probe polling interval must fit inside its observation window.');
        }

        $hosts = [];

        foreach (self::ExpectedMatrix as $key => $expected) {
            $profile = $profiles[$key];

            if ($profile->key !== $key || $profile->ownership !== $expected['ownership'] || $profile->validationMode !== $expected['validation']) {
                throw new InvalidArgumentException("Profile {$key} does not match the required ownership and validation mode.");
            }

            $hosts[] = $profile->endpoint->host;
        }

        if (count(array_unique($hosts)) !== count($hosts)) {
            throw new InvalidArgumentException('Every KSeF DEMO profile must use an isolated tenant.');
        }

        if ($nativeBrokerSender !== null) {
            foreach ($profiles as $profile) {
                if (! $profile->endpoint->isNativeBrokered()) {
                    throw new InvalidArgumentException('Every native KSeF profile must use a broker-only placeholder endpoint.');
                }
            }
        }
    }

    public static function enabled(): bool
    {
        return \getenv('FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED') === 'yes';
    }

    public static function fromEnvironment(#[SensitiveParameter] VerifiedLaunchManifest $launchManifest): never
    {
        $launchManifest->sha256();
    }

    public static function fromNativeBrokerSession(#[SensitiveParameter] NativeBrokerSession $session): self
    {
        $authority = $session->authority;
        $expectedProfiles = self::profileKeys();
        sort($expectedProfiles, SORT_STRING);

        if ($authority->evidenceContract !== self::EvidenceContract
            || $authority->profiles !== $expectedProfiles
            || count($authority->signedAuthorizations) !== 4) {
            throw new InvalidArgumentException('The native broker session does not authorize the exact S0.4 matrix.');
        }

        $limits = $authority->probePlan->limits();
        self::assertSafeLiveLimits($limits);
        $targets = $authority->probePlan->targets();
        $profiles = [];
        $authorizations = [];

        foreach ($authority->signedAuthorizations as $document) {
            $authorization = SignedLiveProbeAuthorization::fromArray($document);
            $profile = $authorization->target['profile'];

            if (isset($authorizations[$profile])) {
                throw new InvalidArgumentException('The native S0.4 authorization set contains a duplicate profile.');
            }

            $authorizations[$profile] = $document;
        }

        foreach (self::profileKeys() as $index => $key) {
            $target = $targets[$index] ?? null;
            $authorization = $authorizations[$key] ?? null;

            if (! is_array($target) || ! is_array($authorization)) {
                throw new InvalidArgumentException("The native S0.4 profile {$key} is incomplete.");
            }

            $profiles[$key] = KsefDemoProfile::fromNativeBrokerTarget(
                $key,
                $target,
                $authorization,
                $authority->supervisorAttestation->launchManifestSha256,
            );
        }

        return new self(
            $profiles,
            $limits['poll_window_ms'],
            $limits['poll_interval_ms'],
            $limits['max_search_pages'],
            $limits['pre_send_observation_window_ms'],
            $limits['visibility_window_ms'],
            $limits['visibility_poll_interval_ms'],
            $limits['connect_timeout_ms'],
            $limits['request_timeout_ms'],
            $limits['minimum_pdf_size_bytes'],
            new NativeBrokerSaloonSender($session),
        );
    }

    public function nativeBrokerSession(): NativeBrokerSession
    {
        return $this->nativeBrokerSender?->session()
            ?? throw new RuntimeException('The native S0.4 broker session is unavailable.');
    }

    public function nativeBrokerSender(): NativeBrokerSaloonSender
    {
        return $this->nativeBrokerSender
            ?? throw new RuntimeException('The native S0.4 broker sender is unavailable.');
    }

    public function usesNativeBroker(): bool
    {
        return $this->nativeBrokerSender !== null;
    }

    /**
     * @return array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}
     */
    public function evidenceLimits(): array
    {
        return [
            'poll_window_ms' => $this->pollWindowMs,
            'poll_interval_ms' => $this->pollIntervalMs,
            'max_search_pages' => $this->maxSearchPages,
            'pre_send_observation_window_ms' => $this->preSendObservationWindowMs,
            'visibility_window_ms' => $this->visibilityWindowMs,
            'visibility_poll_interval_ms' => $this->visibilityPollIntervalMs,
            'connect_timeout_ms' => $this->connectTimeoutMs,
            'request_timeout_ms' => $this->requestTimeoutMs,
            'minimum_pdf_size_bytes' => $this->minimumPdfSizeBytes,
        ];
    }

    public function hasVerifiedAuthorizations(): bool
    {
        foreach ($this->profiles as $profile) {
            if (! $profile->hasVerifiedAuthorization()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function signedAuthorizations(): array
    {
        if (! $this->hasVerifiedAuthorizations()) {
            throw new RuntimeException('The complete four-profile signed KSeF authorization batch is required.');
        }

        return array_map(
            static fn (#[SensitiveParameter] KsefDemoProfile $profile): array => $profile->verifiedSignedAuthorization(),
            array_values($this->profiles),
        );
    }

    public function launchManifestSha256(): string
    {
        $launchManifestSha256 = null;

        foreach ($this->profiles as $profile) {
            $harness = $profile->verifiedAuthorizationEnvelope()['harness'] ?? null;
            $profileLaunchManifestSha256 = is_array($harness)
                ? ($harness['launch_manifest_sha256'] ?? null)
                : null;

            if (! is_string($profileLaunchManifestSha256)
                || preg_match('/^[a-f0-9]{64}$/', $profileLaunchManifestSha256) !== 1
                || ($launchManifestSha256 !== null
                    && ! \hash_equals($launchManifestSha256, $profileLaunchManifestSha256))) {
                throw new RuntimeException('The four-profile KSeF authorization batch must bind one exact launch manifest.');
            }

            $launchManifestSha256 = $profileLaunchManifestSha256;
        }

        if ($launchManifestSha256 === null) {
            throw new RuntimeException('The KSeF authorization batch has no launch-manifest binding.');
        }

        return $launchManifestSha256;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    protected function buildUnsignedEvidencePayload(
        string $repositoryRoot,
        string $fixturePath,
        string $fixtureSha256,
        array $fixture,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        VerifiedFreshClaimGrant $verifiedFreshClaimGrant,
    ): array {
        $authorizations = $this->signedAuthorizations();
        $firstEnvelope = $authorizations[0]['envelope'] ?? null;
        $harness = is_array($firstEnvelope) ? ($firstEnvelope['harness'] ?? null) : null;

        if (! is_array($firstEnvelope)
            || ! is_array($harness)
            || ! is_string($firstEnvelope['signer_id'] ?? null)
            || ! is_string($harness['repository_commit'] ?? null)
            || ! is_string($harness['code_sha256'] ?? null)
            || ! is_string($harness['launch_manifest_sha256'] ?? null)
            || ! \hash_equals($this->launchManifestSha256(), $harness['launch_manifest_sha256'])) {
            throw new RuntimeException('The KSeF authorization batch has no canonical signer or harness evidence.');
        }

        $references = [];

        foreach ($authorizations as $authorization) {
            $envelope = $authorization['envelope'] ?? null;

            if (! is_array($envelope)
                || ! is_array($envelope['target'] ?? null)
                || ! is_string($envelope['target']['profile'] ?? null)
                || ! is_string($envelope['challenge'] ?? null)) {
                throw new RuntimeException('A KSeF authorization cannot be referenced by post-run evidence.');
            }

            $references[] = [
                'profile' => $envelope['target']['profile'],
                'challenge' => $envelope['challenge'],
                'sha256' => LiveEvidenceAttestationGuard::signedDocumentSha256($authorization),
            ];
        }

        $archivedHarness = LiveEvidenceAttestationGuard::harnessSnapshot(
            $repositoryRoot,
            self::EvidenceContract,
        );

        if (! \hash_equals(
            $harness['code_sha256'],
            \hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($archivedHarness)),
        )) {
            throw new RuntimeException('The archived KSeF harness does not match the pre-run authorization batch.');
        }

        return LiveEvidenceAttestationGuard::buildUnsignedEvidencePayload(
            self::EvidenceContract,
            $fixturePath,
            $fixtureSha256,
            $harness['repository_commit'],
            $harness['code_sha256'],
            $harness['launch_manifest_sha256'],
            $archivedHarness,
            $runStartedAt,
            $runFinishedAt,
            'ksef_demo',
            [
                'local_claim' => LiveEvidenceAttestationGuard::buildConsumptionReceipt(
                    $authorizations,
                    $runStartedAt,
                ),
                'authority_receipt' => $verifiedFreshClaimGrant->toArray(),
                'effect_execution_receipts' => [],
            ],
            $references,
            LiveEvidenceAttestationGuard::evidenceCommitments(
                $authorizations,
                $fixture,
                self::EvidenceContract,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    public function buildNativeUnsignedEvidencePayload(
        string $repositoryRoot,
        string $fixturePath,
        string $fixtureSha256,
        #[SensitiveParameter] array $fixture,
        VerifiedLiveProviderRun $providerRun,
        VerifiedFreshClaimGrant $verifiedFreshClaimGrant,
    ): array {
        if (! $this->usesNativeBroker()) {
            throw new RuntimeException('Canonical KSeF evidence requires the native broker transport.');
        }

        $authorizations = $this->signedAuthorizations();
        $firstEnvelope = $authorizations[0]['envelope'] ?? null;
        $harness = is_array($firstEnvelope) ? ($firstEnvelope['harness'] ?? null) : null;

        if (! is_array($firstEnvelope)
            || ! is_array($harness)
            || ! is_string($harness['repository_commit'] ?? null)
            || ! is_string($harness['code_sha256'] ?? null)
            || ! is_string($harness['launch_manifest_sha256'] ?? null)
            || ! hash_equals($this->launchManifestSha256(), $harness['launch_manifest_sha256'])) {
            throw new RuntimeException('The native KSeF authorization batch has no canonical harness evidence.');
        }

        $references = [];

        foreach ($authorizations as $authorization) {
            $envelope = $authorization['envelope'] ?? null;

            if (! is_array($envelope)
                || ! is_array($envelope['target'] ?? null)
                || ! is_string($envelope['target']['profile'] ?? null)
                || ! is_string($envelope['challenge'] ?? null)) {
                throw new RuntimeException('A native KSeF authorization cannot be referenced by live evidence.');
            }

            $references[] = [
                'profile' => $envelope['target']['profile'],
                'challenge' => $envelope['challenge'],
                'sha256' => LiveEvidenceAttestationGuard::signedDocumentSha256($authorization),
            ];
        }

        $archivedHarness = LiveEvidenceAttestationGuard::harnessSnapshot(
            $repositoryRoot,
            self::EvidenceContract,
        );

        if (! hash_equals(
            $harness['code_sha256'],
            hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($archivedHarness)),
        )) {
            throw new RuntimeException('The archived native KSeF harness does not match its authorization batch.');
        }

        $session = $this->nativeBrokerSession();
        $claimStartedAt = new DateTimeImmutable(
            $session->authority->runStartedAt,
            new DateTimeZone('UTC'),
        );

        return LiveEvidenceAttestationGuard::buildLiveUnsignedEvidencePayload(
            self::EvidenceContract,
            $fixturePath,
            $fixtureSha256,
            $harness['repository_commit'],
            $harness['code_sha256'],
            $archivedHarness,
            $verifiedFreshClaimGrant,
            $providerRun,
            $authorizations,
            LiveEvidenceAttestationGuard::buildConsumptionReceipt($authorizations, $claimStartedAt),
            $references,
            LiveEvidenceAttestationGuard::evidenceCommitments(
                $authorizations,
                $fixture,
                self::EvidenceContract,
            ),
            $this->nativeBrokerSender()->effectExecutionReceipts(),
            $session,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{unsigned_path: string, authorization_paths: list<string>}
     */
    public function publishNativeUnsignedEvidenceSidecar(
        string $repositoryRoot,
        #[SensitiveParameter] array $payload,
        VerifiedLiveProviderRun $providerRun,
        VerifiedFreshClaimGrant $verifiedFreshClaimGrant,
    ): array {
        if (! $this->usesNativeBroker()) {
            throw new RuntimeException('Only the native KSeF transport may publish a canonical live sidecar.');
        }

        return LiveEvidenceAttestationGuard::writeLiveUnsignedEvidenceSidecar(
            $repositoryRoot,
            $payload,
            $this->signedAuthorizations(),
            $verifiedFreshClaimGrant,
            $providerRun,
            self::MaximumAuthorizationTtlSeconds,
            $this->nativeBrokerSession(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $trustedOperatorSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     * @return array{unsigned_path: string, authorization_paths: list<string>}
     */
    protected function publishUnsignedEvidenceSidecar(
        string $repositoryRoot,
        array $payload,
        array $trustedOperatorSigners,
        array $trustedConsumptionAuthorities,
    ): array {
        return LiveEvidenceAttestationGuard::writeUnsignedEvidenceSidecar(
            $repositoryRoot,
            $payload,
            $this->signedAuthorizations(),
            self::MaximumAuthorizationTtlSeconds,
            $trustedOperatorSigners,
            $trustedConsumptionAuthorities,
        );
    }

    public function destroyBindingKeys(): void
    {
        foreach ($this->profiles as $profile) {
            $profile->destroyBindingKey();
        }
    }

    /** @param array<string, mixed> $limits */
    public static function assertSafeLiveLimits(array $limits): void
    {
        $expectedKeys = [
            'poll_window_ms',
            'poll_interval_ms',
            'max_search_pages',
            'pre_send_observation_window_ms',
            'visibility_window_ms',
            'visibility_poll_interval_ms',
            'connect_timeout_ms',
            'request_timeout_ms',
            'minimum_pdf_size_bytes',
        ];
        $actualKeys = array_keys($limits);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('The live KSeF probe limits must use the exact signed contract.');
        }

        foreach ($limits as $value) {
            if (! is_int($value)) {
                throw new InvalidArgumentException('Every live KSeF probe limit must be an integer.');
            }
        }

        if ($limits['poll_window_ms'] < self::DefaultPollWindowMs
            || $limits['poll_window_ms'] > self::MaximumPollWindowMs
            || $limits['poll_interval_ms'] < self::MinimumPollIntervalMs
            || $limits['poll_interval_ms'] > self::DefaultPollIntervalMs
            || $limits['max_search_pages'] < self::DefaultMaxSearchPages
            || $limits['max_search_pages'] > self::MaximumSearchPages
            || $limits['pre_send_observation_window_ms'] < self::DefaultPreSendObservationWindowMs
            || $limits['pre_send_observation_window_ms'] > self::MaximumPreSendObservationWindowMs
            || $limits['visibility_window_ms'] < self::DefaultVisibilityWindowMs
            || $limits['visibility_window_ms'] > self::MaximumVisibilityWindowMs
            || $limits['visibility_poll_interval_ms'] < self::MinimumVisibilityPollIntervalMs
            || $limits['visibility_poll_interval_ms'] > self::DefaultVisibilityPollIntervalMs
            || $limits['connect_timeout_ms'] < self::DefaultConnectTimeoutMs
            || $limits['connect_timeout_ms'] > self::MaximumConnectTimeoutMs
            || $limits['request_timeout_ms'] < self::DefaultRequestTimeoutMs
            || $limits['request_timeout_ms'] > self::MaximumRequestTimeoutMs
            || $limits['minimum_pdf_size_bytes'] < self::DefaultMinimumPdfSizeBytes
            || $limits['minimum_pdf_size_bytes'] > self::MaximumPdfSizeBytes
            || ! self::pollingIntervalsFitWithinWindows($limits)) {
            throw new InvalidArgumentException('The live KSeF probe limits are outside the bounded safety policy.');
        }
    }

    /** @return list<string> */
    public static function profileKeys(): array
    {
        return array_keys(self::ExpectedMatrix);
    }

    public static function assertSecureConfigurationFileForTesting(): void
    {
        $contents = self::secureConfigurationContents();

        if (\function_exists('sodium_memzero')) {
            \sodium_memzero($contents);
        }
    }

    public static function assertSecureBindingKeyFileForTesting(#[SensitiveParameter] string $configuredPath): void
    {
        $bindingKey = self::secureBindingKey($configuredPath);

        if (\function_exists('sodium_memzero')) {
            \sodium_memzero($bindingKey);
        }
    }

    private static function requiredEnvironment(string $name): string
    {
        $value = \getenv($name);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing {$name}.");
        }

        return $value;
    }

    private static function secureConfigurationContents(): string
    {
        $configuredPath = self::requiredEnvironment('FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE');

        return self::secureOwnerOnlyFileContents(
            $configuredPath,
            'KSEF_DEMO_CONFIG_FILE_NOT_CANONICAL',
            'KSEF_DEMO_CONFIG_FILE_METADATA_UNAVAILABLE',
            'KSEF_DEMO_CONFIG_FILE_INSECURE_METADATA',
        );
    }

    private static function secureBindingKey(string $configuredPath): string
    {
        $encoded = trim(self::secureOwnerOnlyFileContents(
            $configuredPath,
            'KSEF_DEMO_BINDING_KEY_FILE_NOT_CANONICAL',
            'KSEF_DEMO_BINDING_KEY_FILE_INSECURE_METADATA',
            'KSEF_DEMO_BINDING_KEY_FILE_INSECURE_METADATA',
        ));
        $bindingKey = \base64_decode($encoded, true);

        if (! is_string($bindingKey)
            || strlen($bindingKey) !== 32
            || \base64_encode($bindingKey) !== $encoded) {
            throw new InvalidArgumentException('The KSeF DEMO binding key must be canonical base64 for exactly 32 bytes.');
        }

        return $bindingKey;
    }

    private static function secureOwnerOnlyFileContents(
        string $configuredPath,
        string $notCanonicalReason,
        string $metadataUnavailableReason,
        string $insecureMetadataReason,
    ): string {
        clearstatcache(true, $configuredPath);

        if (! str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            || is_link($configuredPath)
            || ! \function_exists('posix_geteuid')) {
            throw new InvalidArgumentException(
                \function_exists('posix_geteuid') ? $notCanonicalReason : $metadataUnavailableReason,
            );
        }

        $path = \realpath($configuredPath);
        $root = \realpath(dirname(__DIR__, 3));

        if ($path === false
            || $path !== $configuredPath
            || $root === false
            || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException($notCanonicalReason);
        }

        $handle = @fopen($path, 'rb');

        if (! is_resource($handle)) {
            throw new InvalidArgumentException($metadataUnavailableReason);
        }

        try {
            $openedMetadata = fstat($handle);
            clearstatcache(true, $configuredPath);
            $pathMetadata = lstat($configuredPath);

            if (! self::sameRegularFile($openedMetadata, $pathMetadata)
                || \realpath($configuredPath) !== $path
                || is_link($configuredPath)) {
                throw new InvalidArgumentException($notCanonicalReason);
            }

            $owner = $openedMetadata['uid'] ?? null;
            $permissions = $openedMetadata['mode'] ?? null;

            if (! is_int($owner) || ! is_int($permissions)) {
                throw new InvalidArgumentException($metadataUnavailableReason);
            }

            if ($owner !== posix_geteuid() || ($permissions & 0177) !== 0) {
                throw new InvalidArgumentException($insecureMetadataReason);
            }

            $contents = stream_get_contents($handle);
            clearstatcache(true, $configuredPath);
            $finalMetadata = lstat($configuredPath);

            if (! is_string($contents)
                || ! self::sameRegularFile($openedMetadata, $finalMetadata)
                || \realpath($configuredPath) !== $path
                || is_link($configuredPath)) {
                throw new InvalidArgumentException($notCanonicalReason);
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string|int, mixed>|false  $openedMetadata
     * @param  array<string|int, mixed>|false  $pathMetadata
     */
    private static function sameRegularFile(array|false $openedMetadata, array|false $pathMetadata): bool
    {
        if (! is_array($openedMetadata) || ! is_array($pathMetadata)) {
            return false;
        }

        foreach (['dev', 'ino', 'mode', 'nlink', 'uid'] as $key) {
            if (! is_int($openedMetadata[$key] ?? null)
                || ! is_int($pathMetadata[$key] ?? null)
                || $openedMetadata[$key] !== $pathMetadata[$key]) {
                return false;
            }
        }

        return ($openedMetadata['mode'] & 0170000) === 0100000
            && $openedMetadata['nlink'] === 1;
    }

    /**
     * @param  array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $limits
     */
    private static function pollingIntervalsFitWithinWindows(array $limits): bool
    {
        return $limits['poll_interval_ms'] <= $limits['pre_send_observation_window_ms']
            && $limits['poll_interval_ms'] <= $limits['poll_window_ms']
            && $limits['visibility_poll_interval_ms'] <= $limits['visibility_window_ms'];
    }
}

final class VerifiedKsefDemoLiveAuthorization
{
    public static function fromEnvironment(): never
    {
        if (! KsefDemoProbeConfiguration::enabled()) {
            throw new RuntimeException('Set FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED=yes to explicitly run the mutating KSeF DEMO probe.');
        }

        VerifiedLaunchManifest::consumeFromSupervisorFd6();
    }
}

final class KsefDemoProfile
{
    public const OperatorAttestationContract = LiveEvidenceAttestationGuard::AuthorizationContract;

    private const MaxAttestationTtlSeconds = 86_400;

    private const MinimumRemainingAuthorizationSeconds = 21_600;

    /** @var array<string, mixed>|null */
    private ?array $verifiedAuthorizationEnvelope = null;

    /** @var array<string, mixed>|null */
    private ?array $verifiedSignedAuthorization = null;

    /** @var array<string, string>|null */
    private ?array $trustedAuthorizationSigners = null;

    private ?string $attestationBindingKey = null;

    /**
     * @param  array<string, mixed>  $validInvoice
     * @param  array<string, mixed>  $invalidInvoice
     */
    public function __construct(
        public string $key,
        public KsefOwnership $ownership,
        public KsefValidationMode $validationMode,
        #[SensitiveParameter] public KsefDemoEndpoint $endpoint,
        #[SensitiveParameter] public array $validInvoice,
        #[SensitiveParameter] public array $invalidInvoice,
        public string $expectedValidationField,
        public string $expectedKsefEnvironment,
        public mixed $expectedGovAutoSendMode,
        public bool $expectedValidateInvoicesForGov,
        public bool $expectedBuyerCompany,
        public bool $expectedThrowawayTenant,
        public bool $expectedEmailDeliveryDisabled,
        public bool $expectedPaymentsDisabled,
        public bool $expectedWebhooksDisabled,
        public DateTimeImmutable $operatorAttestedAt,
        public DateTimeImmutable $operatorAttestationExpiresAt,
        #[SensitiveParameter] public string $settingsChecksum,
        private bool $nativeBrokered = false,
    ) {
        self::validateTemplate($validInvoice, true);
        self::validateTemplate($invalidInvoice, false);
        self::forbidUnsafeFields($validInvoice);
        self::forbidUnsafeFields($invalidInvoice);

        if ($expectedValidationField !== 'buyer_tax_no') {
            throw new InvalidArgumentException('The KSeF validation gate requires the documented buyer_tax_no leaf field.');
        }

        if (! array_key_exists($expectedValidationField, $validInvoice) || ! array_key_exists($expectedValidationField, $invalidInvoice)) {
            throw new InvalidArgumentException('The expected validation field must exist in both invoice templates.');
        }

        if ($validInvoice[$expectedValidationField] === $invalidInvoice[$expectedValidationField]) {
            throw new InvalidArgumentException('The invalid invoice must alter the expected validation field.');
        }

        if (! is_string($validInvoice['buyer_tax_no'])
            || preg_match('/^[0-9]{10}$/D', $validInvoice['buyer_tax_no']) !== 1
            || $invalidInvoice['buyer_tax_no'] !== '') {
            throw new InvalidArgumentException('The S0.4 validation stimulus requires one canonical valid NIP and one exactly empty buyer_tax_no.');
        }

        $validControl = $validInvoice;
        $invalidControl = $invalidInvoice;
        unset($validControl[$expectedValidationField], $invalidControl[$expectedValidationField]);

        if (self::canonicalize($validControl) !== self::canonicalize($invalidControl)) {
            throw new InvalidArgumentException('The invalid invoice must differ only at the expected validation field.');
        }

        if ($expectedKsefEnvironment !== 'demo') {
            throw new InvalidArgumentException('Every KSeF profile must attest the DEMO environment.');
        }

        if (! $expectedThrowawayTenant
            || ! $expectedEmailDeliveryDisabled
            || ! $expectedPaymentsDisabled
            || ! $expectedWebhooksDisabled) {
            throw new InvalidArgumentException('Every KSeF profile must carry its own complete safety attestation.');
        }

        $attestationTtlMicroseconds = self::instantMicroseconds($operatorAttestationExpiresAt)
            - self::instantMicroseconds($operatorAttestedAt);

        if ($operatorAttestedAt->getOffset() !== 0
            || $operatorAttestationExpiresAt->getOffset() !== 0
            || $attestationTtlMicroseconds < 1
            || $attestationTtlMicroseconds > self::MaxAttestationTtlSeconds * 1_000_000) {
            throw new InvalidArgumentException('The KSeF profile safety attestation must be UTC and valid for at most 24 hours.');
        }

        if ($ownership === KsefOwnership::ExplicitSdk && $expectedGovAutoSendMode !== null) {
            throw new InvalidArgumentException('ExplicitSdk requires gov_auto_send_mode=null.');
        }

        if ($ownership === KsefOwnership::ProviderAutoSend && (! is_scalar($expectedGovAutoSendMode) || trim((string) $expectedGovAutoSendMode) === '')) {
            throw new InvalidArgumentException('ProviderAutoSend requires an explicitly attested auto-send mode.');
        }

        if ($ownership === KsefOwnership::ProviderAutoSend && $expectedGovAutoSendMode !== 'pl_companies') {
            throw new InvalidArgumentException('The S0.4 ProviderAutoSend gate requires the conservative pl_companies mode.');
        }

        if (! $expectedBuyerCompany) {
            throw new InvalidArgumentException('Every S0.4 profile requires a buyer_company=true DEMO sample.');
        }

        foreach ([$validInvoice, $invalidInvoice] as $invoice) {
            if (($invoice['buyer_company'] ?? null) !== $expectedBuyerCompany || ($invoice['buyer_country'] ?? null) !== 'PL') {
                throw new InvalidArgumentException('Every KSeF DEMO template must explicitly describe the attested Polish company buyer.');
            }
        }

        $expectedValidationSetting = $validationMode === KsefValidationMode::BlockInvalid;

        if ($expectedValidateInvoicesForGov !== $expectedValidationSetting) {
            throw new InvalidArgumentException('The validation mode does not match validate_invoices_for_gov.');
        }

        $expectedSettingsChecksum = self::settingsChecksumFor(
            $endpoint,
            $ownership,
            $validationMode,
            $expectedKsefEnvironment,
            $expectedGovAutoSendMode,
            $expectedValidateInvoicesForGov,
            $expectedBuyerCompany,
            $expectedThrowawayTenant,
            $expectedEmailDeliveryDisabled,
            $expectedPaymentsDisabled,
            $expectedWebhooksDisabled,
            $operatorAttestedAt,
            $operatorAttestationExpiresAt,
        );

        if (! preg_match('/^[a-f0-9]{64}$/', $settingsChecksum)
            || (! $nativeBrokered && ! \hash_equals($expectedSettingsChecksum, $settingsChecksum))
            || ($nativeBrokered && ! $endpoint->isNativeBrokered())) {
            throw new InvalidArgumentException('The settings checksum does not match the declared KSeF profile.');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $signedAuthorization
     */
    public static function fromNativeBrokerTarget(
        string $key,
        #[SensitiveParameter] array $target,
        #[SensitiveParameter] array $signedAuthorization,
        string $expectedLaunchManifestSha256,
    ): self {
        $authorization = SignedLiveProbeAuthorization::fromArray($signedAuthorization);
        $ownership = KsefOwnership::tryFrom(self::string($target, 'ownership'));
        $validationMode = KsefValidationMode::tryFrom(self::string($target, 'validation_mode'));
        $validInvoice = $target['valid_invoice'] ?? null;
        $invalidInvoice = $target['invalid_invoice'] ?? null;

        if (($target['profile'] ?? null) !== $key
            || ($target['target_key'] ?? null) !== $key
            || ! $ownership instanceof KsefOwnership
            || ! $validationMode instanceof KsefValidationMode
            || ! is_array($validInvoice)
            || array_is_list($validInvoice)
            || ! is_array($invalidInvoice)
            || array_is_list($invalidInvoice)
            || $authorization->evidenceContract !== KsefDemoProbeConfiguration::EvidenceContract
            || $authorization->target['profile'] !== $key
            || ! \hash_equals($authorization->harness['launch_manifest_sha256'], $expectedLaunchManifestSha256)) {
            throw new InvalidArgumentException("The native S0.4 profile {$key} does not match its verified authorization.");
        }

        $profile = new self(
            $key,
            $ownership,
            $validationMode,
            KsefDemoEndpoint::forNativeBroker(
                $key,
                self::string($target, 'expected_account_fingerprint'),
            ),
            $validInvoice,
            $invalidInvoice,
            self::string($target, 'expected_validation_field'),
            self::string($target, 'ksef_environment'),
            $target['gov_auto_send_mode'] ?? null,
            self::boolean($target, 'validate_invoices_for_gov'),
            self::boolean($target, 'buyer_company'),
            self::boolean($target, 'throwaway_tenant'),
            self::boolean($target, 'email_delivery_disabled'),
            self::boolean($target, 'payments_disabled'),
            self::boolean($target, 'webhooks_disabled'),
            $authorization->issuedAtInstant(),
            $authorization->expiresAtInstant(),
            self::string($target, 'settings_checksum'),
            true,
        );
        $profile->verifiedAuthorizationEnvelope = $authorization->envelope();
        $profile->verifiedSignedAuthorization = $signedAuthorization;

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $probeLimits
     * @param  array<string, string>|null  $trustedSigners
     */
    public static function fromArray(
        string $key,
        #[SensitiveParameter] array $data,
        DateTimeImmutable $now,
        array $probeLimits,
        ?array $trustedSigners = null,
        #[SensitiveParameter] ?string $bindingKey = null,
        ?string $expectedLaunchManifestSha256 = null,
    ): self {
        $settings = $data['settings'] ?? null;
        $validInvoice = $data['valid_invoice'] ?? null;
        $invalidInvoice = $data['invalid_invoice'] ?? null;
        $signedAttestation = $data['operator_attestation'] ?? null;

        if (! self::hasExactKeys($data, [
            'base_url',
            'token',
            'tenant_fingerprint',
            'valid_invoice',
            'invalid_invoice',
            'expected_validation_field',
            'settings',
            'operator_attestation',
        ])
            || ! is_array($settings)
            || ! is_array($validInvoice)
            || ! is_array($invalidInvoice)
            || ! is_array($signedAttestation)) {
            throw new InvalidArgumentException("Profile {$key} requires the exact signed settings and template contract.");
        }

        if (! self::hasExactKeys($settings, [
            'ownership',
            'validation_mode',
            'ksef_environment',
            'gov_auto_send_mode',
            'validate_invoices_for_gov',
            'buyer_company',
            'throwaway_tenant',
            'email_delivery_disabled',
            'payments_disabled',
            'webhooks_disabled',
            'settings_checksum',
        ])) {
            throw new InvalidArgumentException("Profile {$key} settings do not match the exact authorization contract.");
        }

        if (! is_string($bindingKey) || strlen($bindingKey) !== 32) {
            throw new InvalidArgumentException("Profile {$key} requires its non-persisted 32-byte authorization binding key.");
        }

        $attestationEnvelope = $trustedSigners === null
            ? LiveEvidenceAttestationGuard::assertAuthorizedNow(
                $signedAttestation,
                self::repositoryRoot(),
                $now,
                self::MaxAttestationTtlSeconds,
            )
            : LiveEvidenceAttestationGuard::assertAuthorizationSignature(
                $signedAttestation,
                $now,
                self::MaxAttestationTtlSeconds,
                $trustedSigners,
            );
        $attestedHarness = $attestationEnvelope['harness'] ?? null;

        if (! is_array($attestedHarness)) {
            throw new InvalidArgumentException("Profile {$key} operator authorization has no harness contract.");
        }

        $attestedLaunchManifestSha256 = self::string($attestedHarness, 'launch_manifest_sha256');

        if (($trustedSigners === null && $expectedLaunchManifestSha256 === null)
            || ($expectedLaunchManifestSha256 !== null
                && (preg_match('/^[a-f0-9]{64}$/', $expectedLaunchManifestSha256) !== 1
                    || ! \hash_equals($expectedLaunchManifestSha256, $attestedLaunchManifestSha256)))) {
            throw new InvalidArgumentException("Profile {$key} operator authorization does not match the supervised launch manifest.");
        }

        $attestedAt = self::attestationDate($attestationEnvelope, 'issued_at');
        $expiresAt = self::attestationDate($attestationEnvelope, 'expires_at');

        if (self::instantMicroseconds($expiresAt) - self::instantMicroseconds($now)
            < self::MinimumRemainingAuthorizationSeconds * 1_000_000) {
            throw new InvalidArgumentException("Profile {$key} operator authorization does not cover the bounded live run.");
        }
        $ownership = KsefOwnership::tryFrom(self::string($settings, 'ownership'));
        $validationMode = KsefValidationMode::tryFrom(self::string($settings, 'validation_mode'));

        if (! $ownership instanceof KsefOwnership || ! $validationMode instanceof KsefValidationMode) {
            throw new InvalidArgumentException("Profile {$key} has an unsupported ownership or validation mode.");
        }

        $profile = new self(
            $key,
            $ownership,
            $validationMode,
            new KsefDemoEndpoint(
                $key,
                self::string($data, 'base_url'),
                self::string($data, 'token'),
                self::string($data, 'tenant_fingerprint'),
            ),
            $validInvoice,
            $invalidInvoice,
            self::string($data, 'expected_validation_field'),
            self::string($settings, 'ksef_environment'),
            $settings['gov_auto_send_mode'] ?? null,
            self::boolean($settings, 'validate_invoices_for_gov'),
            self::boolean($settings, 'buyer_company'),
            self::boolean($settings, 'throwaway_tenant'),
            self::boolean($settings, 'email_delivery_disabled'),
            self::boolean($settings, 'payments_disabled'),
            self::boolean($settings, 'webhooks_disabled'),
            $attestedAt,
            $expiresAt,
            self::string($settings, 'settings_checksum'),
        );

        $profile->assertExactAuthorizationDomain($attestationEnvelope, $probeLimits, $bindingKey);

        $profile->verifiedAuthorizationEnvelope = $attestationEnvelope;
        $profile->verifiedSignedAuthorization = $signedAttestation;
        $profile->trustedAuthorizationSigners = $trustedSigners;
        $profile->attestationBindingKey = $bindingKey;

        return $profile;
    }

    /** @return array<string, mixed> */
    public function verifiedAuthorizationEnvelope(): array
    {
        if ($this->verifiedAuthorizationEnvelope === null) {
            throw new RuntimeException('This KSeF profile has no verified live operator authorization.');
        }

        return $this->verifiedAuthorizationEnvelope;
    }

    public function hasVerifiedAuthorization(): bool
    {
        return $this->verifiedAuthorizationEnvelope !== null
            && $this->verifiedSignedAuthorization !== null
            && ($this->attestationBindingKey !== null || $this->nativeBrokered);
    }

    public function verifiedAuthorizationSha256(): string
    {
        if ($this->verifiedSignedAuthorization === null) {
            throw new RuntimeException('This KSeF profile has no signed live operator authorization.');
        }

        return \hash('sha256', LiveEvidenceAttestationGuard::canonicalJson($this->verifiedSignedAuthorization));
    }

    /** @return array<string, mixed> */
    public function verifiedSignedAuthorization(): array
    {
        if ($this->verifiedSignedAuthorization === null) {
            throw new RuntimeException('This KSeF profile has no signed live operator authorization.');
        }

        return $this->verifiedSignedAuthorization;
    }

    public function assertWriteAuthorizedAt(DateTimeImmutable $now, int $minimumRemainingSeconds = 1): void
    {
        if ($this->verifiedSignedAuthorization === null || $this->verifiedAuthorizationEnvelope === null) {
            throw new RuntimeException('A verified KSeF profile authorization is required at the write boundary.');
        }

        if ($this->nativeBrokered) {
            $authorization = SignedLiveProbeAuthorization::fromArray($this->verifiedSignedAuthorization);

            if (! \hash_equals(
                LiveEvidenceAttestationGuard::canonicalJson($this->verifiedAuthorizationEnvelope),
                LiveEvidenceAttestationGuard::canonicalJson($authorization->envelope()),
            )
                || $authorization->issuedAtInstant() > $now
                || $authorization->expiresAtInstant() <= $now
                || $minimumRemainingSeconds < 1
                || self::instantMicroseconds($authorization->expiresAtInstant()) - self::instantMicroseconds($now)
                    < $minimumRemainingSeconds * 1_000_000) {
                throw new RuntimeException('The native KSeF profile authorization is invalid at the write boundary.');
            }

            return;
        }

        $envelope = $this->trustedAuthorizationSigners === null
            ? LiveEvidenceAttestationGuard::assertAuthorizedNow(
                $this->verifiedSignedAuthorization,
                self::repositoryRoot(),
                $now,
                self::MaxAttestationTtlSeconds,
            )
            : LiveEvidenceAttestationGuard::assertAuthorizationSignature(
                $this->verifiedSignedAuthorization,
                $now,
                self::MaxAttestationTtlSeconds,
                $this->trustedAuthorizationSigners,
            );

        if (! \hash_equals(
            LiveEvidenceAttestationGuard::canonicalJson($this->verifiedAuthorizationEnvelope),
            LiveEvidenceAttestationGuard::canonicalJson($envelope),
        )) {
            throw new RuntimeException('The KSeF profile authorization changed before the write boundary.');
        }

        if ($minimumRemainingSeconds < 1
            || self::instantMicroseconds($this->operatorAttestationExpiresAt) - self::instantMicroseconds($now)
                < $minimumRemainingSeconds * 1_000_000) {
            throw new RuntimeException('The KSeF profile authorization expires before the bounded write request can finish.');
        }
    }

    public function destroyBindingKey(): void
    {
        if ($this->attestationBindingKey === null) {
            return;
        }

        if (\function_exists('sodium_memzero')) {
            \sodium_memzero($this->attestationBindingKey);
        }

        $this->attestationBindingKey = null;
    }

    public function expectedKsefSendCount(): int
    {
        return $this->ownership === KsefOwnership::ExplicitSdk ? 1 : 0;
    }

    public static function settingsChecksumFor(
        #[SensitiveParameter] KsefDemoEndpoint $endpoint,
        KsefOwnership $ownership,
        KsefValidationMode $validationMode,
        string $ksefEnvironment,
        mixed $govAutoSendMode,
        bool $validateInvoicesForGov,
        bool $buyerCompany,
        bool $throwawayTenant,
        bool $emailDeliveryDisabled,
        bool $paymentsDisabled,
        bool $webhooksDisabled,
        DateTimeImmutable $operatorAttestedAt,
        DateTimeImmutable $operatorAttestationExpiresAt,
    ): string {
        $settings = \json_encode([
            'profile_key' => $endpoint->profileKey,
            'tenant_host' => $endpoint->host,
            'tenant_fingerprint' => $endpoint->expectedFingerprint,
            'ksef_environment' => $ksefEnvironment,
            'ownership' => $ownership->value,
            'validation_mode' => $validationMode->value,
            'gov_auto_send_mode' => $govAutoSendMode,
            'validate_invoices_for_gov' => $validateInvoicesForGov,
            'buyer_company' => $buyerCompany,
            'throwaway_tenant' => $throwawayTenant,
            'email_delivery_disabled' => $emailDeliveryDisabled,
            'payments_disabled' => $paymentsDisabled,
            'webhooks_disabled' => $webhooksDisabled,
            'operator_attested_at' => $operatorAttestedAt->format(DATE_RFC3339),
            'operator_attestation_expires_at' => $operatorAttestationExpiresAt->format(DATE_RFC3339),
        ], JSON_THROW_ON_ERROR);

        return \hash('sha256', "fakturownia-s0.4-settings|{$settings}");
    }

    /**
     * @param  array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $probeLimits
     * @return array<string, mixed>
     */
    public function operatorAttestationEnvelope(
        string $signerId,
        array $probeLimits,
        string $repositoryCommit,
        string $launchManifestSha256,
        string $authorityId,
        string $authorityPolicySha256,
        string $storeId,
        string $storeIdentitySha256,
        string $runId,
        #[SensitiveParameter] string $bindingKey,
    ): array {
        return LiveEvidenceAttestationGuard::buildUnsignedAuthorizationEnvelope(
            $signerId,
            $this->operatorAttestedAt,
            $this->operatorAttestationExpiresAt,
            KsefDemoProbeConfiguration::EvidenceContract,
            $this->authorizationChallenge($bindingKey),
            $repositoryCommit,
            LiveEvidenceAttestationGuard::harnessCodeSha256(
                self::repositoryRoot(),
                KsefDemoProbeConfiguration::EvidenceContract,
            ),
            $launchManifestSha256,
            $this->authorizationTarget($bindingKey),
            $this->authorizationCommitments($bindingKey, $probeLimits),
            [
                'authority_id' => $authorityId,
                'authority_policy_sha256' => $authorityPolicySha256,
                'store_id' => $storeId,
                'store_identity_sha256' => $storeIdentitySha256,
                'run_id' => $runId,
                'replay_policy' => LiveEvidenceAttestationGuard::ConsumptionReplayPolicy,
            ],
            $probeLimits,
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array{poll_window_ms: int, poll_interval_ms: int, max_search_pages: int, pre_send_observation_window_ms: int, visibility_window_ms: int, visibility_poll_interval_ms: int, connect_timeout_ms: int, request_timeout_ms: int, minimum_pdf_size_bytes: int}  $limits
     */
    private function assertExactAuthorizationDomain(array $envelope, array $limits, #[SensitiveParameter] string $bindingKey): void
    {
        $consumption = $envelope['consumption'] ?? null;

        if (! is_array($consumption)) {
            throw new InvalidArgumentException("Profile {$this->key} authorization has no single-use consumption contract.");
        }

        $expected = $this->operatorAttestationEnvelope(
            self::string($envelope, 'signer_id'),
            $limits,
            self::string(is_array($envelope['harness'] ?? null) ? $envelope['harness'] : [], 'repository_commit'),
            self::string(is_array($envelope['harness'] ?? null) ? $envelope['harness'] : [], 'launch_manifest_sha256'),
            self::string($consumption, 'authority_id'),
            self::string($consumption, 'authority_policy_sha256'),
            self::string($consumption, 'store_id'),
            self::string($consumption, 'store_identity_sha256'),
            self::string($consumption, 'run_id'),
            $bindingKey,
        );

        if (! \hash_equals(
            LiveEvidenceAttestationGuard::canonicalJson($expected),
            LiveEvidenceAttestationGuard::canonicalJson($envelope),
        )) {
            throw new InvalidArgumentException("Profile {$this->key} operator authorization does not match the current code, tenant, policy, safety, templates, limits or single-use run.");
        }
    }

    private function authorizationChallenge(#[SensitiveParameter] string $bindingKey): string
    {
        return \base64_encode(\hash_hmac('sha256', "fakturownia-s0.4-{$this->key}-authorization-challenge", $bindingKey, true));
    }

    /** @return array<string, mixed> */
    private function authorizationTarget(#[SensitiveParameter] string $bindingKey): array
    {
        return [
            'environment' => 'ksef_demo',
            'profile' => $this->key,
            'tenant_hmac_sha256' => self::hmacSha256([
                'profile_key' => $this->key,
                'host' => $this->endpoint->host,
            ], $bindingKey),
            'account_hmac_sha256' => self::hmacSha256([
                'profile_key' => $this->key,
                'account_fingerprint' => $this->endpoint->expectedFingerprint,
            ], $bindingKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{scheme: string, configuration_hmac_sha256: string, policy_hmac_sha256: string, safety_hmac_sha256: string, templates_hmac_sha256: string}
     */
    private function authorizationCommitments(#[SensitiveParameter] string $bindingKey, array $limits): array
    {
        return [
            'scheme' => LiveEvidenceAttestationGuard::CommitmentScheme,
            'configuration_hmac_sha256' => self::hmacSha256([
                'base_url' => $this->endpoint->baseUrl,
                'token' => $this->endpoint->token,
                'settings_checksum' => $this->settingsChecksum,
                'limits' => $limits,
            ], $bindingKey),
            'policy_hmac_sha256' => self::hmacSha256([
                'ownership' => $this->ownership->value,
                'validation_mode' => $this->validationMode->value,
                'ksef_environment' => $this->expectedKsefEnvironment,
                'gov_auto_send_mode' => $this->expectedGovAutoSendMode,
                'validate_invoices_for_gov' => $this->expectedValidateInvoicesForGov,
                'buyer_company' => $this->expectedBuyerCompany,
                'expected_validation_field' => $this->expectedValidationField,
                'issue_ksef_behavior' => 'never_send',
                'ensure_accepted' => 'separate_operation',
            ], $bindingKey),
            'safety_hmac_sha256' => self::hmacSha256([
                'throwaway_tenant' => $this->expectedThrowawayTenant,
                'email_delivery_disabled' => $this->expectedEmailDeliveryDisabled,
                'payments_disabled' => $this->expectedPaymentsDisabled,
                'webhooks_disabled' => $this->expectedWebhooksDisabled,
            ], $bindingKey),
            'templates_hmac_sha256' => self::hmacSha256([
                'valid_invoice' => $this->validInvoice,
                'invalid_invoice' => $this->invalidInvoice,
            ], $bindingKey),
        ];
    }

    /** @param array<array-key, mixed> $value */
    private static function hmacSha256(#[SensitiveParameter] array $value, #[SensitiveParameter] string $bindingKey): string
    {
        if (strlen($bindingKey) !== 32 || array_is_list($value)) {
            throw new InvalidArgumentException('KSeF authorization commitments require one 32-byte key and JSON objects.');
        }

        return \hash_hmac('sha256', LiveEvidenceAttestationGuard::canonicalJson($value), $bindingKey);
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function instantMicroseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U') * 1_000_000) + (int) $date->format('u');
    }

    private static function canonicalize(#[SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $template */
    private static function validateTemplate(#[SensitiveParameter] array $template, bool $strictValues): void
    {
        $required = ['department_id', 'issue_date', 'sell_date', 'payment_to_kind', 'buyer_name', 'buyer_tax_no', 'buyer_company', 'buyer_country', 'currency', 'positions'];

        if (! self::hasExactKeys($template, $required)
            || ! is_array($template['positions'])
            || ! array_is_list($template['positions'])
            || $template['positions'] === []) {
            throw new InvalidArgumentException('Every KSeF invoice template must contain all stable business fields.');
        }

        foreach ($required as $field) {
            if ($field === 'positions') {
                continue;
            }

            if (! is_scalar($template[$field])) {
                throw new InvalidArgumentException("Invoice field {$field} must be scalar.");
            }

            if ($strictValues && trim((string) $template[$field]) === '') {
                throw new InvalidArgumentException("Valid invoice field {$field} cannot be empty.");
            }
        }

        if (! is_bool($template['buyer_company'])) {
            throw new InvalidArgumentException('Invoice field buyer_company must be boolean.');
        }

        foreach ($template['positions'] as $position) {
            if (! is_array($position)
                || ! self::hasExactKeys($position, ['name', 'quantity', 'price_net', 'tax'])) {
                throw new InvalidArgumentException('Every KSeF invoice position requires name, quantity, price_net and tax.');
            }

            foreach (['name', 'quantity', 'price_net', 'tax'] as $field) {
                if (! is_scalar($position[$field]) || ($strictValues && trim((string) $position[$field]) === '')) {
                    throw new InvalidArgumentException("Invoice position field {$field} must be a non-empty scalar.");
                }
            }
        }
    }

    /** @param array<string, mixed> $template */
    private static function forbidUnsafeFields(#[SensitiveParameter] array $template): void
    {
        array_walk_recursive($template, static function (#[SensitiveParameter] mixed $value, string|int $key): void {
            if (! is_string($key)) {
                return;
            }

            $forbidden = [
                'api_token',
                'gov_save_and_send',
                'send_to_ksef',
                'send_email',
                'email_to',
                'buyer_email',
                'seller_email',
                'oid',
                'id',
            ];

            if (in_array($key, $forbidden, true) || str_ends_with($key, '_email')) {
                throw new InvalidArgumentException("Field {$key} is forbidden in a KSeF contract probe template.");
            }
        });
    }

    /** @param array<string, mixed> $data */
    private static function string(#[SensitiveParameter] array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing non-empty string {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function boolean(#[SensitiveParameter] array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Missing boolean {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function attestationDate(#[SensitiveParameter] array $data, string $key): DateTimeImmutable
    {
        $value = self::string($data, $key);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->getOffset() !== 0
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException("Invalid datetime {$key}.");
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $expectedKeys
     */
    private static function hasExactKeys(#[SensitiveParameter] array $data, array $expectedKeys): bool
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }
}

final class KsefDemoEndpoint
{
    public string $baseUrl;

    public string $host;

    private bool $nativeBrokered = false;

    public function __construct(
        public string $profileKey,
        #[SensitiveParameter] string $baseUrl,
        #[SensitiveParameter] public string $token,
        #[SensitiveParameter] public string $expectedFingerprint,
        bool $nativeBrokered = false,
    ) {
        $parts = \parse_url($baseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $plainHttps = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($path, ['', '/'], true)
            && ! isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);

        $nativeHost = preg_match('/^(?:explicit-block|explicit-persist|auto-block|auto-persist)\.s04-native\.invalid$/D', $host) === 1;

        if (! $plainHttps
            || ($nativeBrokered && ! $nativeHost)
            || (! $nativeBrokered && ! preg_match('/^s04-demo-[a-z0-9-]+\.fakturownia\.pl$/', $host))) {
            throw new InvalidArgumentException('Use an approved s04-demo-* Fakturownia.pl throwaway tenant over plain HTTPS.');
        }

        if (($nativeBrokered && $token !== NativeBrokerSaloonSender::TokenSentinel)
            || $token === ''
            || ! preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)) {
            throw new InvalidArgumentException('Confirm the exact KSeF DEMO tenant fingerprint and token.');
        }

        $this->baseUrl = "https://{$host}";
        $this->host = $host;
        $this->nativeBrokered = $nativeBrokered;
    }

    public static function forNativeBroker(
        string $profileKey,
        #[SensitiveParameter] string $expectedFingerprint,
    ): self {
        $hostKey = match ($profileKey) {
            'explicit_block' => 'explicit-block',
            'explicit_persist' => 'explicit-persist',
            'auto_block' => 'auto-block',
            'auto_persist' => 'auto-persist',
            default => throw new InvalidArgumentException('The native KSeF profile key is invalid.'),
        };

        return new self(
            $profileKey,
            "https://{$hostKey}.s04-native.invalid",
            NativeBrokerSaloonSender::TokenSentinel,
            $expectedFingerprint,
            true,
        );
    }

    public function isNativeBrokered(): bool
    {
        return $this->nativeBrokered;
    }

    public static function fingerprintFor(
        string $profileKey,
        #[SensitiveParameter] string $host,
        #[SensitiveParameter] string $accountId,
    ): string {
        return \hash('sha256', "fakturownia-s0.4|{$profileKey}|".strtolower($host)."|{$accountId}");
    }

    public function verifyAccountId(#[SensitiveParameter] string $accountId): void
    {
        if ($this->nativeBrokered) {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $accountId) !== 1) {
                throw new RuntimeException('The native KSeF account ID is not canonical.');
            }

            return;
        }

        $actual = self::fingerprintFor($this->profileKey, $this->host, $accountId);

        if (! \hash_equals($this->expectedFingerprint, $actual)) {
            throw new RuntimeException('The API token does not match the allowlisted KSeF DEMO account.');
        }
    }
}
