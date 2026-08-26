<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ClaimCursor;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionAuthority;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceipt;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionReceiptEnvelope;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\FreshClaimGrant;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

use function array_column;
use function array_diff;
use function array_diff_key;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function base64_decode;
use function base64_encode;
use function chmod;
use function class_exists;
use function clearstatcache;
use function count;
use function defined;
use function dirname;
use function explode;
use function extension_loaded;
use function fclose;
use function fflush;
use function file_get_contents;
use function fileowner;
use function fileperms;
use function flock;
use function fopen;
use function fstat;
use function fsync;
use function function_exists;
use function fwrite;
use function get_loaded_extensions;
use function getenv;
use function hash;
use function hash_equals;
use function hash_file;
use function hash_hmac;
use function hrtime;
use function in_array;
use function ini_get;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function lstat;
use function max;
use function php_ini_loaded_file;
use function php_ini_scanned_files;
use function phpversion;
use function posix_geteuid;
use function posix_getpwuid;
use function preg_match;
use function preg_split;
use function proc_close;
use function proc_open;
use function readlink;
use function realpath;
use function rtrim;
use function sodium_crypto_sign_verify_detached;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;
use function umask;
use function unlink;
use function usort;

final class LiveEvidenceAttestationGuard
{
    /**
     * @var \WeakMap<VerifiedFreshClaimGrant, array{
     *     origin: 'pinned_remote'|'mock_test',
     *     receipt_sha256: string,
     *     request_sha256: string,
     *     authorization_batch_sha256: string,
     *     last_observed_microseconds: int
     * }>|null
     */
    private static ?\WeakMap $verifiedFreshGrantRegistry = null;

    /**
     * @var \WeakMap<LiveProviderRunHandle, array{
     *     evidence_contract: string,
     *     environment: string,
     *     launch_manifest_sha256: string,
     *     claim_request_sha256: string,
     *     started_at: string,
     *     started_monotonic_nanoseconds: int
     * }>|null
     */
    private static ?\WeakMap $liveProviderRunHandleRegistry = null;

    /**
     * @var \WeakMap<VerifiedLiveProviderRun, array{
     *     origin: 'real_provider',
     *     evidence_contract: string,
     *     environment: string,
     *     launch_manifest_sha256: string,
     *     claim_request_sha256: string,
     *     started_at: string,
     *     finished_at: string,
     *     finished_monotonic_nanoseconds: int
     * }>|null
     */
    private static ?\WeakMap $verifiedLiveProviderRunRegistry = null;

    private const GitBinary = '/usr/bin/git';

    private const SyncBinary = '/bin/sync';

    /** @var list<string> */
    private const ComposerBootstrapFiles = [
        'vendor/autoload.php',
        'vendor/composer/ClassLoader.php',
        'vendor/composer/InstalledVersions.php',
        'vendor/composer/autoload_classmap.php',
        'vendor/composer/autoload_files.php',
        'vendor/composer/autoload_namespaces.php',
        'vendor/composer/autoload_psr4.php',
        'vendor/composer/autoload_real.php',
        'vendor/composer/autoload_static.php',
        'vendor/composer/installed.php',
        'vendor/composer/platform_check.php',
    ];

    /** @var array<string, list<string>> */
    private const EvidenceHarnessFiles = [
        'fakturownia-invoice-identity-s0.3-v1' => [
            'tests/Contract/InvoiceIdentityContractProbeTest.php',
            'tests/Contract/Support/ProbeConfiguration.php',
            'tests/Contract/Support/InvoiceIdentityProbe.php',
            'tests/Contract/Support/LiveEvidenceAttestationGuard.php',
            'bin/fakturownia-live-evidence-launcher.php',
            'src/ContractTesting/LiveEvidence/BrokeredExecutionRequiredException.php',
            'src/ContractTesting/LiveEvidence/CanonicalCodec.php',
            'src/ContractTesting/LiveEvidence/ClaimCursor.php',
            'src/ContractTesting/LiveEvidence/ConsumptionAuthority.php',
            'src/ContractTesting/LiveEvidence/ConsumptionClaimRequest.php',
            'src/ContractTesting/LiveEvidence/ConsumptionDisposition.php',
            'src/ContractTesting/LiveEvidence/ConsumptionReceipt.php',
            'src/ContractTesting/LiveEvidence/ConsumptionReceiptEnvelope.php',
            'src/ContractTesting/LiveEvidence/FreshClaimGrant.php',
            'src/ContractTesting/LiveEvidence/LiveEffectDescriptor.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationBatch.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationBatchAggregator.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationVerifier.php',
            'src/ContractTesting/LiveEvidence/PendingLiteralRemoteConsumptionClaim.php',
            'src/ContractTesting/LiveEvidence/PinnedLiveProbeTrustStore.php',
            'src/ContractTesting/LiveEvidence/PinnedRepositorySnapshotReader.php',
            'src/ContractTesting/LiveEvidence/RecoveredConsumedProof.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionAuthorityPolicy.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionAuthorityPolicyStore.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionClaimRequest.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionCoordinator.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionReceiptVerifier.php',
            'src/ContractTesting/LiveEvidence/SaloonRuntimeIsolationGuard.php',
            'src/ContractTesting/LiveEvidence/SignedLiveProbeAuthorization.php',
            'src/ContractTesting/LiveEvidence/TrustedLiveProbeOperatorKeys.php',
            'src/ContractTesting/LiveEvidence/VerifiedLaunchManifest.php',
            'src/ContractTesting/LiveEvidence/VerifiedRemoteConsumptionGrant.php',
            'tests/Contract/Support/LiveProviderRunHandle.php',
            'tests/Contract/Support/LiveProviderTransportOrigin.php',
            'tests/Contract/Support/VerifiedFreshClaimGrant.php',
            'tests/Contract/Support/VerifiedLiveProviderRun.php',
            'tests/Pest.php',
            'phpunit.xml.dist',
            'composer.json',
            'composer.lock',
            'tests/Fixtures/Contract/trusted-operator-signers.json',
            'tests/Fixtures/Contract/trusted-consumption-authorities.json',
        ],
        'fakturownia-ksef-demo-s0.4-v1' => [
            'tests/Contract/KsefDemoContractProbeTest.php',
            'tests/Contract/Support/KsefDemoProbeConfiguration.php',
            'tests/Contract/Support/KsefDemoContractProbe.php',
            'tests/Contract/Support/LiveEvidenceAttestationGuard.php',
            'bin/fakturownia-live-evidence-launcher.php',
            'src/ContractTesting/LiveEvidence/BrokeredExecutionRequiredException.php',
            'src/ContractTesting/LiveEvidence/CanonicalCodec.php',
            'src/ContractTesting/LiveEvidence/ClaimCursor.php',
            'src/ContractTesting/LiveEvidence/ConsumptionAuthority.php',
            'src/ContractTesting/LiveEvidence/ConsumptionClaimRequest.php',
            'src/ContractTesting/LiveEvidence/ConsumptionDisposition.php',
            'src/ContractTesting/LiveEvidence/ConsumptionReceipt.php',
            'src/ContractTesting/LiveEvidence/ConsumptionReceiptEnvelope.php',
            'src/ContractTesting/LiveEvidence/FreshClaimGrant.php',
            'src/ContractTesting/LiveEvidence/LiveEffectDescriptor.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationBatch.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationBatchAggregator.php',
            'src/ContractTesting/LiveEvidence/LiveProbeAuthorizationVerifier.php',
            'src/ContractTesting/LiveEvidence/PendingLiteralRemoteConsumptionClaim.php',
            'src/ContractTesting/LiveEvidence/PinnedLiveProbeTrustStore.php',
            'src/ContractTesting/LiveEvidence/PinnedRepositorySnapshotReader.php',
            'src/ContractTesting/LiveEvidence/RecoveredConsumedProof.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionAuthorityPolicy.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionAuthorityPolicyStore.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionClaimRequest.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionCoordinator.php',
            'src/ContractTesting/LiveEvidence/RemoteConsumptionReceiptVerifier.php',
            'src/ContractTesting/LiveEvidence/SaloonRuntimeIsolationGuard.php',
            'src/ContractTesting/LiveEvidence/SignedLiveProbeAuthorization.php',
            'src/ContractTesting/LiveEvidence/TrustedLiveProbeOperatorKeys.php',
            'src/ContractTesting/LiveEvidence/VerifiedLaunchManifest.php',
            'src/ContractTesting/LiveEvidence/VerifiedRemoteConsumptionGrant.php',
            'tests/Contract/Support/LiveProviderRunHandle.php',
            'tests/Contract/Support/LiveProviderTransportOrigin.php',
            'tests/Contract/Support/VerifiedFreshClaimGrant.php',
            'tests/Contract/Support/VerifiedLiveProviderRun.php',
            'tests/Pest.php',
            'phpunit.xml.dist',
            'composer.json',
            'composer.lock',
            'tests/Fixtures/Contract/trusted-operator-signers.json',
            'tests/Fixtures/Contract/trusted-consumption-authorities.json',
        ],
    ];

    public const AuthorizationContract = SignedLiveProbeAuthorization::Contract;

    public const EvidenceContract = 'cieplik206.fakturownia.live-evidence-attestation';

    public const EvidencePayloadContract = 'cieplik206.fakturownia.live-evidence-result-payload';

    public const TestEvidenceContract = 'cieplik206.fakturownia.test-only-evidence-attestation';

    public const TestEvidencePayloadContract = 'cieplik206.fakturownia.test-only-evidence-result-payload';

    public const LiveRuntimeOriginsContract = 'cieplik206.fakturownia.live-runtime-origins';

    public const LiveRuntimeOriginsScheme = 'pinned-remote+real-provider-weakmap-v1';

    public const Version = SignedLiveProbeAuthorization::Version;

    public const Algorithm = SignedLiveProbeAuthorization::Algorithm;

    public const TrustedSignersContract = 'cieplik206.fakturownia.trusted-operator-signers';

    public const CommitmentScheme = SignedLiveProbeAuthorization::CommitmentScheme;

    public const EvidenceCommitmentScheme = 'authorization-aggregate-sha256-v1';

    public const ConsumptionReceiptContract = ConsumptionReceiptEnvelope::Contract;

    public const ConsumptionClaimRequestContract = ConsumptionClaimRequest::Contract;

    public const ConsumptionReplayPolicy = ConsumptionClaimRequest::ReplayPolicy;

    public const FreshConsumptionDisposition = ConsumptionDisposition::FreshDirectGrant->value;

    public const RecoveredConsumptionDisposition = ConsumptionDisposition::RecoveredConsumedProof->value;

    private const MaxEvidenceRunSeconds = 21600;

    private const MaxHistoricalAuthorizationTtlSeconds = 2592000;

    private const MaxEvidenceSigningDelaySeconds = 86400;

    /**
     * @param  array<string, mixed>  $signedDocument
     * @param  array<string, string>|null  $trustedSigners
     * @return array<string, mixed>
     */
    public static function assertAuthorizedNow(
        array $signedDocument,
        string $repositoryRoot,
        DateTimeImmutable $now,
        int $maximumTtlSeconds,
        ?array $trustedSigners = null,
    ): array {
        $envelope = $trustedSigners === null
            ? self::assertAuthorizationNowWithSigners($signedDocument, $now, $maximumTtlSeconds, self::loadTrustedSigners())
            : self::assertAuthorizationNowWithSigners($signedDocument, $now, $maximumTtlSeconds, $trustedSigners);

        self::assertHarnessMatchesRepositoryCommit(
            $repositoryRoot,
            $envelope['evidence_contract'],
            $envelope['harness']['repository_commit'],
            $envelope['harness']['code_sha256'],
        );

        return $envelope;
    }

    /**
     * Signature-only seam for deterministic unit tests. Live probes must call assertAuthorizedNow.
     *
     * @param  array<string, mixed>  $signedDocument
     * @param  array<string, string>  $trustedSigners
     * @return array<string, mixed>
     */
    public static function assertAuthorizationSignature(
        array $signedDocument,
        DateTimeImmutable $now,
        int $maximumTtlSeconds,
        array $trustedSigners,
    ): array {
        if ($trustedSigners === []) {
            throw new InvalidArgumentException('The signature-only test seam requires an explicit non-empty signer map.');
        }

        return self::assertAuthorizationNowWithSigners(
            $signedDocument,
            $now,
            $maximumTtlSeconds,
            $trustedSigners,
        );
    }

    /**
     * @param  array<string, mixed>  $signedDocument
     * @param  array<string, string>  $trustedSigners
     * @return array<string, mixed>
     */
    private static function assertAuthorizationNowWithSigners(
        array $signedDocument,
        DateTimeImmutable $now,
        int $maximumTtlSeconds,
        array $trustedSigners,
    ): array {
        [$envelope, $issuedAt, $expiresAt] = self::assertTrustedSignature(
            $signedDocument,
            self::AuthorizationContract,
            $maximumTtlSeconds,
            $trustedSigners,
        );
        self::assertAuthorizationEnvelopeShape($envelope);
        $nowTimestamp = self::instantMicroseconds($now);

        if (self::instantMicroseconds($issuedAt) > $nowTimestamp
            || self::instantMicroseconds($expiresAt) <= $nowTimestamp) {
            throw new InvalidArgumentException('The live evidence authorization is not valid now.');
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $signedDocument
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $fixture
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     * @return array<string, mixed>
     */
    public static function assertHistoricalEvidence(
        array $signedDocument,
        array $signedAuthorizations,
        array $fixture,
        string $repositoryRoot,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumRunSeconds,
        int $maximumAuthorizationTtlSeconds,
        int $maximumEvidenceTtlSeconds,
        int $maximumSigningDelaySeconds,
        ?array $trustedSigners = null,
        ?array $trustedConsumptionAuthorities = null,
    ): array {
        return self::assertHistoricalEvidenceInternal(
            $signedDocument,
            $signedAuthorizations,
            $fixture,
            $repositoryRoot,
            $runStartedAt,
            $runFinishedAt,
            $maximumRunSeconds,
            $maximumAuthorizationTtlSeconds,
            $maximumEvidenceTtlSeconds,
            $maximumSigningDelaySeconds,
            $trustedSigners,
            $trustedConsumptionAuthorities,
            true,
            self::EvidenceContract,
            true,
        );
    }

    /**
     * Explicit signature-only seam for deterministic unit tests. Live and release gates
     * must call assertHistoricalEvidence so repository/runtime integrity is enforced.
     *
     * @param  array<string, mixed>  $signedDocument
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $fixture
     * @param  array<string, string>  $trustedSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     * @return array<string, mixed>
     */
    public static function assertHistoricalEvidenceSignatures(
        array $signedDocument,
        array $signedAuthorizations,
        array $fixture,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumRunSeconds,
        int $maximumAuthorizationTtlSeconds,
        int $maximumEvidenceTtlSeconds,
        int $maximumSigningDelaySeconds,
        array $trustedSigners,
        array $trustedConsumptionAuthorities,
    ): array {
        if ($trustedSigners === []) {
            throw new InvalidArgumentException('The historical evidence test seam requires an explicit non-empty signer map.');
        }

        return self::assertHistoricalEvidenceInternal(
            $signedDocument,
            $signedAuthorizations,
            $fixture,
            '',
            $runStartedAt,
            $runFinishedAt,
            $maximumRunSeconds,
            $maximumAuthorizationTtlSeconds,
            $maximumEvidenceTtlSeconds,
            $maximumSigningDelaySeconds,
            $trustedSigners,
            $trustedConsumptionAuthorities,
            false,
            self::EvidenceContract,
            true,
        );
    }

    /**
     * Deterministic semantic test seam. Its distinct signed contract is never accepted
     * by the release/capability `passed` path.
     *
     * @param  array<string, mixed>  $signedDocument
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $fixture
     * @param  array<string, string>  $trustedSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     * @return array<string, mixed>
     */
    public static function assertHistoricalTestEvidenceSignatures(
        array $signedDocument,
        array $signedAuthorizations,
        array $fixture,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumRunSeconds,
        int $maximumAuthorizationTtlSeconds,
        int $maximumEvidenceTtlSeconds,
        int $maximumSigningDelaySeconds,
        array $trustedSigners,
        array $trustedConsumptionAuthorities,
    ): array {
        if ($trustedSigners === []) {
            throw new InvalidArgumentException('The historical test-evidence seam requires an explicit non-empty signer map.');
        }

        return self::assertHistoricalEvidenceInternal(
            $signedDocument,
            $signedAuthorizations,
            $fixture,
            '',
            $runStartedAt,
            $runFinishedAt,
            $maximumRunSeconds,
            $maximumAuthorizationTtlSeconds,
            $maximumEvidenceTtlSeconds,
            $maximumSigningDelaySeconds,
            $trustedSigners,
            $trustedConsumptionAuthorities,
            false,
            self::TestEvidenceContract,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $signedDocument
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $fixture
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     * @return array<string, mixed>
     */
    private static function assertHistoricalEvidenceInternal(
        array $signedDocument,
        array $signedAuthorizations,
        array $fixture,
        string $repositoryRoot,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumRunSeconds,
        int $maximumAuthorizationTtlSeconds,
        int $maximumEvidenceTtlSeconds,
        int $maximumSigningDelaySeconds,
        ?array $trustedSigners,
        ?array $trustedConsumptionAuthorities,
        bool $verifyHarness,
        string $expectedEvidenceContract,
        bool $requireLiveOrigins,
    ): array {
        $trustedSigners ??= self::loadTrustedSigners(requiredRole: 'operator_attestation');
        $trustedConsumptionAuthorities ??= self::loadTrustedSigners(requiredRole: 'consumption_authority');
        self::assertProvisionedDisjointTrustRoles($trustedSigners, $trustedConsumptionAuthorities);

        [$envelope, $issuedAt, $expiresAt] = self::assertTrustedSignature(
            $signedDocument,
            $expectedEvidenceContract,
            $maximumEvidenceTtlSeconds,
            $trustedSigners,
        );
        self::assertEvidenceEnvelopeShape($envelope, $expectedEvidenceContract, $requireLiveOrigins);
        $envelopeRunStartedAt = self::strictUtcDate($envelope['run']['started_at']);
        $envelopeRunFinishedAt = self::strictUtcDate($envelope['run']['finished_at']);
        $runMicroseconds = self::instantMicroseconds($runFinishedAt) - self::instantMicroseconds($runStartedAt);
        $signingDelayMicroseconds = self::instantMicroseconds($issuedAt) - self::instantMicroseconds($runFinishedAt);
        $localClaim = $envelope['consumption']['local_claim'];
        $authorityReceipt = $envelope['consumption']['authority_receipt'];
        $claimedAt = self::strictUtcDate($localClaim['claimed_at']);

        if ($maximumRunSeconds < 1
            || $runStartedAt->format('U.u') !== $envelopeRunStartedAt->format('U.u')
            || $runFinishedAt->format('U.u') !== $envelopeRunFinishedAt->format('U.u')
            || $runMicroseconds < 1
            || $runMicroseconds > $maximumRunSeconds * 1_000_000
            || $maximumSigningDelaySeconds < 0
            || $signingDelayMicroseconds < 0
            || $signingDelayMicroseconds > $maximumSigningDelaySeconds * 1_000_000
            || self::instantMicroseconds($claimedAt) > self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds($expiresAt) <= self::instantMicroseconds($issuedAt)) {
            throw new InvalidArgumentException('The historical evidence result has an invalid post-run signing window.');
        }

        if ($verifyHarness) {
            self::assertArchivedHarnessMatchesRepositoryCommit(
                $repositoryRoot,
                $envelope['evidence']['contract'],
                $envelope['probe']['repository_commit'],
                $envelope['probe']['code_sha256'],
                $envelope['probe']['archived_harness'],
            );
        }

        $authorizationReferences = $envelope['authorizations'];

        if (count($signedAuthorizations) !== count($authorizationReferences)) {
            throw new InvalidArgumentException('The historical evidence does not provide every referenced pre-run authorization.');
        }

        $authorizationsBySha256 = [];

        foreach ($signedAuthorizations as $signedAuthorization) {
            $sha256 = self::signedDocumentSha256($signedAuthorization);

            if (isset($authorizationsBySha256[$sha256])) {
                throw new InvalidArgumentException('The historical evidence repeats a pre-run authorization.');
            }

            $authorizationsBySha256[$sha256] = $signedAuthorization;
        }

        $seenProfiles = [];
        $seenChallenges = [];

        foreach ($authorizationReferences as $reference) {
            if (! is_array($reference)
                || ! self::hasExactKeys($reference, ['profile', 'challenge', 'sha256'])
                || ! is_string($reference['profile'] ?? null)
                || ! is_string($reference['challenge'] ?? null)
                || ! is_string($reference['sha256'] ?? null)
                || isset($seenProfiles[$reference['profile']])
                || isset($seenChallenges[$reference['challenge']])) {
                throw new InvalidArgumentException('The historical evidence authorization references are invalid.');
            }

            $signedAuthorization = $authorizationsBySha256[$reference['sha256']] ?? null;

            if (! is_array($signedAuthorization)) {
                throw new InvalidArgumentException('A referenced pre-run authorization document is missing.');
            }

            $authorization = self::assertHistoricalAuthorizationSignatureWithSigners(
                $signedAuthorization,
                $runStartedAt,
                $runFinishedAt,
                $maximumAuthorizationTtlSeconds,
                $trustedSigners,
            );

            if ($authorization['evidence_contract'] !== $envelope['evidence']['contract']
                || $authorization['harness']['repository_commit'] !== $envelope['probe']['repository_commit']
                || $authorization['harness']['code_sha256'] !== $envelope['probe']['code_sha256']
                || $authorization['harness']['launch_manifest_sha256'] !== $envelope['run']['launch_manifest_sha256']
                || $authorization['target']['environment'] !== $envelope['run']['environment']
                || $authorization['target']['profile'] !== $reference['profile']
                || $authorization['challenge'] !== $reference['challenge']) {
                throw new InvalidArgumentException('A pre-run authorization does not bind this exact historical evidence run.');
            }

            $seenProfiles[$reference['profile']] = true;
            $seenChallenges[$reference['challenge']] = true;
        }

        if (! hash_equals(
            self::canonicalJson($localClaim),
            self::canonicalJson(self::buildConsumptionReceipt($signedAuthorizations, $claimedAt)),
        )) {
            throw new InvalidArgumentException('The post-run evidence does not bind the exact pre-effect authorization consumption receipt.');
        }

        $authorityEnvelope = self::assertConsumptionAuthorityReceiptSignature(
            $authorityReceipt,
            $signedAuthorizations,
            $authorityReceipt['envelope']['claim_request'],
            $maximumAuthorizationTtlSeconds,
            $trustedConsumptionAuthorities,
        );

        if (self::instantMicroseconds(self::strictUtcDate($authorityEnvelope['claim_request']['run_started_at'])) !== self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds(self::strictUtcDate($authorityEnvelope['issued_at'])) < self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds(self::strictUtcDate($authorityEnvelope['issued_at'])) > self::instantMicroseconds($runFinishedAt)
            || $authorityEnvelope['disposition'] !== self::FreshConsumptionDisposition
            || $authorityEnvelope['claim_request']['run_id'] !== $localClaim['run_id']
            || $authorityEnvelope['claim_request']['store_identity_sha256'] !== $localClaim['store_identity_sha256']
            || $authorityEnvelope['claim_request']['harness']['launch_manifest_sha256'] !== $envelope['run']['launch_manifest_sha256']) {
            throw new InvalidArgumentException('The external authority receipt does not cover the exact historical pre-effect boundary.');
        }

        if (! hash_equals(
            self::canonicalJson($envelope['commitments']),
            self::canonicalJson(self::evidenceCommitments(
                $signedAuthorizations,
                $fixture,
                $envelope['evidence']['contract'],
            )),
        )) {
            throw new InvalidArgumentException('The post-run evidence commitments do not match the signed authorizations and sanitized fixture policy.');
        }

        $environmentMatches = match ($envelope['evidence']['contract']) {
            'fakturownia-invoice-identity-s0.3-v1' => in_array($envelope['run']['environment'], ['demo_pl', 'demo_regional'], true)
                && ($fixture['environment'] ?? null) === $envelope['run']['environment']
                && ($fixture['launch_manifest_sha256'] ?? null) === $envelope['run']['launch_manifest_sha256'],
            'fakturownia-ksef-demo-s0.4-v1' => $envelope['run']['environment'] === 'ksef_demo'
                && is_array($fixture['run'] ?? null)
                && hash_equals(
                    self::canonicalJson($fixture['run']),
                    self::canonicalJson($envelope['run']),
                ),
            default => false,
        };

        if (! $environmentMatches) {
            throw new InvalidArgumentException('The post-run evidence environment does not match its contract fixture.');
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $signedAuthorization
     * @param  array<string, string>|null  $trustedSigners
     * @return array<string, mixed>
     */
    public static function assertHistoricalAuthorization(
        array $signedAuthorization,
        string $repositoryRoot,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumTtlSeconds,
        ?array $trustedSigners = null,
    ): array {
        $authorization = self::assertHistoricalAuthorizationSignatureWithSigners(
            $signedAuthorization,
            $runStartedAt,
            $runFinishedAt,
            $maximumTtlSeconds,
            $trustedSigners,
        );

        self::assertHarnessMatchesRepositoryCommit(
            $repositoryRoot,
            $authorization['evidence_contract'],
            $authorization['harness']['repository_commit'],
            $authorization['harness']['code_sha256'],
        );

        return $authorization;
    }

    /**
     * Explicit signature-only seam for deterministic unit tests. Live probes must use
     * assertHistoricalAuthorization so repository/runtime integrity is enforced.
     *
     * @param  array<string, mixed>  $signedAuthorization
     * @param  array<string, string>  $trustedSigners
     * @return array<string, mixed>
     */
    public static function assertHistoricalAuthorizationSignature(
        array $signedAuthorization,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumTtlSeconds,
        array $trustedSigners,
    ): array {
        if ($trustedSigners === []) {
            throw new InvalidArgumentException('The historical authorization test seam requires an explicit non-empty signer map.');
        }

        return self::assertHistoricalAuthorizationSignatureWithSigners(
            $signedAuthorization,
            $runStartedAt,
            $runFinishedAt,
            $maximumTtlSeconds,
            $trustedSigners,
        );
    }

    /**
     * @param  array<string, mixed>  $signedAuthorization
     * @param  array<string, string>|null  $trustedSigners
     * @return array<string, mixed>
     */
    private static function assertHistoricalAuthorizationSignatureWithSigners(
        array $signedAuthorization,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        int $maximumTtlSeconds,
        ?array $trustedSigners,
    ): array {
        [$authorization, $issuedAt, $expiresAt] = self::assertTrustedSignature(
            $signedAuthorization,
            self::AuthorizationContract,
            $maximumTtlSeconds,
            $trustedSigners,
        );
        self::assertAuthorizationEnvelopeShape($authorization);

        if (self::instantMicroseconds($runFinishedAt) <= self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds($runStartedAt) < self::instantMicroseconds($issuedAt)
            || self::instantMicroseconds($runFinishedAt) > self::instantMicroseconds($expiresAt)) {
            throw new InvalidArgumentException('The historical run is outside its pre-run authorization window.');
        }

        return $authorization;
    }

    /** @param array<string, mixed> $signedDocument */
    public static function signedDocumentSha256(array $signedDocument): string
    {
        if (! self::hasExactKeys($signedDocument, ['envelope', 'signature'])) {
            throw new InvalidArgumentException('The signed document must contain only an envelope and signature.');
        }

        return hash('sha256', self::canonicalJson($signedDocument));
    }

    /**
     * @param  array<string, mixed>  $signedDocument
     * @param  array<string, string>|null  $trustedSigners
     * @return array{array<string, mixed>, DateTimeImmutable, DateTimeImmutable}
     */
    private static function assertTrustedSignature(
        array $signedDocument,
        string $expectedContract,
        int $maximumTtlSeconds,
        ?array $trustedSigners,
    ): array {
        self::assertSodiumAvailable();

        if (! self::hasExactKeys($signedDocument, ['envelope', 'signature'])) {
            throw new InvalidArgumentException('The signed attestation must contain only an envelope and signature.');
        }

        $envelope = $signedDocument['envelope'] ?? null;
        $signature = $signedDocument['signature'] ?? null;

        if (! is_array($envelope) || array_is_list($envelope) || ! is_string($signature)) {
            throw new InvalidArgumentException('The signed attestation envelope or signature is invalid.');
        }

        [$issuedAt, $expiresAt] = self::assertBaseEnvelope($envelope, $expectedContract, $maximumTtlSeconds);

        $signerId = $envelope['signer_id'];
        $signers = $trustedSigners ?? self::loadTrustedSigners();
        self::assertTrustedSignerMap($signers);
        $encodedPublicKey = $signers[$signerId] ?? null;

        if (! is_string($encodedPublicKey)) {
            throw new InvalidArgumentException('The attestation signer is not trusted by the pinned repository allowlist.');
        }

        $publicKey = self::decodeCanonicalBase64($encodedPublicKey, 'operator public key');
        $detachedSignature = self::decodeCanonicalBase64($signature, 'detached signature');

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($detachedSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($detachedSignature, self::canonicalJson($envelope), $publicKey)) {
            throw new InvalidArgumentException('The live evidence attestation signature is invalid.');
        }

        return [$envelope, $issuedAt, $expiresAt];
    }

    /** @return array<string, string> */
    public static function loadTrustedSigners(
        ?string $path = null,
        string $requiredRole = 'operator_attestation',
    ): array {
        if (! in_array($requiredRole, ['operator_attestation', 'consumption_authority'], true)) {
            throw new InvalidArgumentException('The trusted signer role is not allowlisted.');
        }

        return self::loadTrustedSignerRoles($path)[$requiredRole];
    }

    /** @return array{operator_attestation: array<string, string>, consumption_authority: array<string, string>} */
    public static function loadTrustedSignerRoles(?string $path = null): array
    {
        self::assertSodiumAvailable();
        $path ??= dirname(__DIR__, 2).'/Fixtures/Contract/trusted-operator-signers.json';
        $contents = self::readPinnedSignerStoreSnapshot($path);

        try {
            $store = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The pinned trusted operator signer store is not valid JSON.', previous: $exception);
        }

        if (! is_array($store)
            || array_is_list($store)
            || ! self::hasExactKeys($store, ['contract', 'version', 'signers'])
            || ($store['contract'] ?? null) !== self::TrustedSignersContract
            || ($store['version'] ?? null) !== self::Version
            || ! is_array($store['signers'] ?? null)
            || ! array_is_list($store['signers'])) {
            throw new RuntimeException('The pinned trusted operator signer store has an invalid contract.');
        }

        $roles = [
            'operator_attestation' => [],
            'consumption_authority' => [],
        ];
        $seenSignerIds = [];
        $seenPublicKeyFingerprints = [];

        foreach ($store['signers'] as $signer) {
            if (! is_array($signer)
                || ! self::hasExactKeys($signer, ['id', 'algorithm', 'public_key', 'roles'])
                || ! is_string($signer['id'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $signer['id']) !== 1
                || ($signer['algorithm'] ?? null) !== self::Algorithm
                || ! is_string($signer['public_key'] ?? null)
                || ! is_array($signer['roles'] ?? null)
                || ! array_is_list($signer['roles'])
                || count($signer['roles']) !== 1
                || count($signer['roles']) !== count(array_unique($signer['roles']))
                || array_diff($signer['roles'], ['operator_attestation', 'consumption_authority']) !== []
                || isset($seenSignerIds[$signer['id']])) {
                throw new RuntimeException('The pinned trusted operator signer store contains an invalid signer.');
            }

            $publicKey = self::decodeCanonicalBase64($signer['public_key'], 'operator public key');
            $publicKeyFingerprint = hash('sha256', $publicKey);

            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || isset($seenPublicKeyFingerprints[$publicKeyFingerprint])) {
                throw new RuntimeException('The pinned operator public key has an invalid length.');
            }

            $seenSignerIds[$signer['id']] = true;
            $seenPublicKeyFingerprints[$publicKeyFingerprint] = true;

            if ($signer['roles'][0] === 'operator_attestation') {
                $roles['operator_attestation'][$signer['id']] = $signer['public_key'];
            } else {
                $roles['consumption_authority'][$signer['id']] = $signer['public_key'];
            }
        }

        return $roles;
    }

    private static function readPinnedSignerStoreSnapshot(string $path): string
    {
        clearstatcache(true, $path);
        $before = lstat($path);

        if (! is_array($before)
            || is_link($path)
            || ($before['mode'] & 0170000) !== 0100000
            || $before['nlink'] !== 1
            || $before['size'] < 1
            || $before['size'] > 65_536) {
            throw new RuntimeException('The pinned trusted operator signer store is missing or unsafe.');
        }

        $handle = fopen($path, 'rb');

        if (! is_resource($handle)) {
            throw new RuntimeException('The pinned trusted operator signer store cannot be opened securely.');
        }

        try {
            $opened = fstat($handle);
            $contents = stream_get_contents($handle, 65_537);
            $afterHandle = fstat($handle);
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $path);
        $afterPath = lstat($path);

        foreach ([$opened, $afterHandle, $afterPath] as $snapshot) {
            if (! is_array($snapshot)
                || ($snapshot['mode'] & 0170000) !== 0100000
                || $snapshot['nlink'] !== 1
                || $snapshot['dev'] !== $before['dev']
                || $snapshot['ino'] !== $before['ino']
                || $snapshot['size'] !== $before['size']) {
                throw new RuntimeException('The pinned trusted operator signer store changed while it was being read.');
            }
        }

        if (! is_string($contents) || strlen($contents) !== $before['size']) {
            throw new RuntimeException('The pinned trusted operator signer store could not be read atomically.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $envelope */
    public static function canonicalJson(array $envelope): string
    {
        return CanonicalCodec::encode($envelope);
    }

    /**
     * Starts the only provider run that may later produce canonical live evidence.
     * The final probe implementation must itself fail closed for every fake transport.
     */
    public static function beginLiveProviderRun(
        LiveProviderTransportOrigin $provider,
        string $evidenceContract,
        string $environment,
    ): LiveProviderRunHandle {
        throw new RuntimeException('A PHP provider run cannot execute live effects; the native one-shot broker is not provisioned.');
    }

    /** Completes a real provider run using internal wall and monotonic clocks. */
    public static function finishLiveProviderRun(LiveProviderRunHandle $handle): VerifiedLiveProviderRun
    {
        $handles = self::liveProviderRunHandleRegistry();
        $context = $handles[$handle] ?? null;

        if (! is_array($context)) {
            throw new InvalidArgumentException('The live provider run handle is unknown or already consumed.');
        }

        $finishedAt = self::systemUtcNow();
        $finishedMonotonicNanoseconds = self::monotonicNanoseconds();

        if ($finishedMonotonicNanoseconds <= $context['started_monotonic_nanoseconds']
            || self::instantMicroseconds($finishedAt) <= self::instantMicroseconds(self::strictUtcDate($context['started_at']))) {
            throw new RuntimeException('The live provider run did not advance both trusted clocks.');
        }

        $issuer = \Closure::bind(
            static fn (): VerifiedLiveProviderRun => new VerifiedLiveProviderRun,
            null,
            VerifiedLiveProviderRun::class,
        );

        $run = $issuer();
        self::verifiedLiveProviderRunRegistry()[$run] = [
            'origin' => 'real_provider',
            'evidence_contract' => $context['evidence_contract'],
            'environment' => $context['environment'],
            'launch_manifest_sha256' => $context['launch_manifest_sha256'],
            'claim_request_sha256' => $context['claim_request_sha256'],
            'started_at' => $context['started_at'],
            'finished_at' => self::canonicalUtc($finishedAt),
            'finished_monotonic_nanoseconds' => $finishedMonotonicNanoseconds,
        ];
        unset($handles[$handle]);

        return $run;
    }

    /** @return array{started_at: string, finished_at: string, environment: string} */
    public static function liveProviderRunWindow(VerifiedLiveProviderRun $run): array
    {
        $context = self::registeredLiveProviderRunContext($run);

        if (! is_array($context) || $context['origin'] !== 'real_provider') {
            throw new InvalidArgumentException('The live provider run is not process-authenticated.');
        }

        return [
            'started_at' => $context['started_at'],
            'finished_at' => $context['finished_at'],
            'environment' => $context['environment'],
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $commitments
     * @param  array<string, mixed>  $consumption
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function buildUnsignedAuthorizationEnvelope(
        string $signerId,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        string $evidenceContract,
        string $challenge,
        string $repositoryCommit,
        string $codeSha256,
        string $launchManifestSha256,
        array $target,
        array $commitments,
        array $consumption,
        array $limits,
    ): array {
        self::harnessManifest($evidenceContract);
        self::assertCanonicalChallenge($challenge);

        $utc = new DateTimeZone('UTC');
        $envelope = [
            'contract' => self::AuthorizationContract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $signerId,
            'issued_at' => $issuedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $expiresAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'evidence_contract' => $evidenceContract,
            'challenge' => $challenge,
            'harness' => [
                'repository_commit' => $repositoryCommit,
                'code_sha256' => $codeSha256,
                'launch_manifest_sha256' => $launchManifestSha256,
            ],
            'target' => $target,
            'commitments' => $commitments,
            'consumption' => $consumption,
            'limits' => $limits,
        ];
        self::assertAuthorizationEnvelopeShape($envelope);

        return $envelope;
    }

    /** @param array<string, mixed> $envelope */
    public static function canonicalUnsignedAuthorizationPayload(array $envelope): string
    {
        self::assertAuthorizationEnvelopeShape($envelope);

        return self::canonicalJson($envelope);
    }

    /**
     * @param  list<array<string, string>>  $authorizations
     * @param  array<string, mixed>  $archivedHarness
     * @param  array<string, mixed>  $consumption
     * @param  array<string, mixed>  $commitments
     * @return array<string, mixed>
     */
    public static function buildUnsignedEvidencePayload(
        string $evidenceContract,
        string $fixturePath,
        string $fixtureSha256,
        string $repositoryCommit,
        string $codeSha256,
        string $launchManifestSha256,
        array $archivedHarness,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $runFinishedAt,
        string $environment,
        array $consumption,
        array $authorizations,
        array $commitments,
    ): array {
        self::harnessManifest($evidenceContract);

        self::assertArchivedHarnessSnapshotShape($archivedHarness);

        if (! self::isContractFixturePath($fixturePath)
            || preg_match('/^[a-f0-9]{64}$/', $fixtureSha256) !== 1
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $repositoryCommit) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $codeSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $launchManifestSha256) !== 1
            || ($archivedHarness['evidence_contract'] ?? null) !== $evidenceContract
            || ! hash_equals($codeSha256, hash('sha256', self::canonicalJson($archivedHarness)))
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $environment) !== 1
            || ! self::hasExactKeys($commitments, [
                'scheme',
                'target_set_sha256',
                'configuration_set_sha256',
                'policy_set_sha256',
                'safety_set_sha256',
                'templates_set_sha256',
                'limits_set_sha256',
                'fixture_policy_sha256',
            ])
            || ($commitments['scheme'] ?? null) !== self::EvidenceCommitmentScheme) {
            throw new InvalidArgumentException('The unsigned test evidence envelope fields are invalid.');
        }

        foreach (array_diff_key($commitments, ['scheme' => true]) as $commitment) {
            if (! is_string($commitment) || preg_match('/^[a-f0-9]{64}$/', $commitment) !== 1) {
                throw new InvalidArgumentException('The live evidence commitments must be reproducible SHA-256 aggregates.');
            }
        }

        $startedTimestamp = self::instantMicroseconds($runStartedAt);
        $finishedTimestamp = self::instantMicroseconds($runFinishedAt);

        if ($finishedTimestamp - $startedTimestamp < 1
            || $finishedTimestamp - $startedTimestamp > self::MaxEvidenceRunSeconds * 1_000_000) {
            throw new InvalidArgumentException('The live evidence result must contain a bounded completed run.');
        }

        $utc = new DateTimeZone('UTC');

        $payload = [
            'contract' => self::TestEvidencePayloadContract,
            'version' => self::Version,
            'evidence' => [
                'contract' => $evidenceContract,
                'fixture_path' => $fixturePath,
                'fixture_sha256' => $fixtureSha256,
            ],
            'probe' => [
                'repository_commit' => $repositoryCommit,
                'code_sha256' => $codeSha256,
                'archived_harness' => $archivedHarness,
            ],
            'run' => [
                'started_at' => $runStartedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                'finished_at' => $runFinishedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                'environment' => $environment,
                'launch_manifest_sha256' => $launchManifestSha256,
            ],
            'consumption' => $consumption,
            'authorizations' => $authorizations,
            'commitments' => $commitments,
        ];

        self::assertEvidencePayloadShape($payload);

        return $payload;
    }

    /**
     * The only builder for canonical live evidence. Both the remote CAS response and
     * the provider run must be process-local brands issued by production-only paths.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $archivedHarness
     * @param  array<string, mixed>  $localConsumptionObservation
     * @param  list<array<string, string>>  $authorizations
     * @param  array<string, mixed>  $commitments
     * @return array<string, mixed>
     */
    public static function buildLiveUnsignedEvidencePayload(
        string $evidenceContract,
        string $fixturePath,
        string $fixtureSha256,
        string $repositoryCommit,
        string $codeSha256,
        array $archivedHarness,
        VerifiedFreshClaimGrant $authorityGrant,
        VerifiedLiveProviderRun $providerRun,
        array $signedAuthorizations,
        array $localConsumptionObservation,
        array $authorizations,
        array $commitments,
    ): array {
        throw new RuntimeException('Canonical live evidence requires supervisor-signed brokered effect-execution receipts.');
    }

    /**
     * Trusted operator signing wrapper. It stamps the actual local signing time instead
     * of accepting a pre-filled issued_at from the unsigned post-run payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function prepareEvidenceEnvelopeForSigning(
        array $payload,
        string $signerId,
        int $ttlSeconds,
    ): array {
        if (($payload['contract'] ?? null) !== self::EvidencePayloadContract) {
            throw new InvalidArgumentException('The production signer accepts only dual-origin live evidence payloads.');
        }

        $issuedAt = self::systemUtcNow();

        return self::buildEvidenceEnvelopeAt(
            $payload,
            $signerId,
            $issuedAt,
            $issuedAt->modify('+'.$ttlSeconds.' seconds'),
        );
    }

    /**
     * Explicit deterministic unit seam. Operator signing workflows must use
     * prepareEvidenceEnvelopeForSigning so issued_at cannot be pre-filled.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function buildEvidenceEnvelopeForTesting(
        array $payload,
        string $signerId,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): array {
        if (($payload['contract'] ?? null) !== self::TestEvidencePayloadContract) {
            throw new InvalidArgumentException('The deterministic signing seam accepts only the distinct test evidence contract.');
        }

        return self::buildEvidenceEnvelopeAt($payload, $signerId, $issuedAt, $expiresAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function buildEvidenceEnvelopeAt(
        array $payload,
        string $signerId,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
    ): array {
        self::assertEvidencePayloadShape($payload);
        $finishedAt = self::strictUtcDate($payload['run']['finished_at']);
        $issuedTimestamp = self::instantMicroseconds($issuedAt);
        $expiresTimestamp = self::instantMicroseconds($expiresAt);
        $finishedTimestamp = self::instantMicroseconds($finishedAt);

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $signerId) !== 1
            || $expiresTimestamp - $issuedTimestamp < 1
            || $expiresTimestamp - $issuedTimestamp > self::MaxHistoricalAuthorizationTtlSeconds * 1_000_000
            || $issuedTimestamp < $finishedTimestamp
            || $issuedTimestamp - $finishedTimestamp > self::MaxEvidenceSigningDelaySeconds * 1_000_000) {
            throw new InvalidArgumentException('The evidence envelope must be stamped by its signer after the bounded run without backdating.');
        }

        $utc = new DateTimeZone('UTC');
        $isLive = $payload['contract'] === self::EvidencePayloadContract;
        $envelope = [
            'contract' => $isLive ? self::EvidenceContract : self::TestEvidenceContract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $signerId,
            'issued_at' => $issuedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $expiresAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'evidence' => $payload['evidence'],
            'probe' => $payload['probe'],
            'run' => $payload['run'],
            'consumption' => $payload['consumption'],
            'authorizations' => $payload['authorizations'],
            'commitments' => $payload['commitments'],
        ];

        if ($isLive) {
            $envelope['origins'] = $payload['origins'];
        }

        self::assertEvidenceEnvelopeShape(
            $envelope,
            $isLive ? self::EvidenceContract : self::TestEvidenceContract,
            $isLive,
        );

        return $envelope;
    }

    /**
     * Fail-closed compatibility entrypoint. A local owner-writable ledger is not a
     * production single-use authority and therefore can never unlock mutating HTTP.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    public static function claimAuthorizationsNow(
        array $signedAuthorizations,
        string $repositoryRoot,
        DateTimeImmutable $now,
        int $maximumTtlSeconds,
    ): never {
        throw new RuntimeException('The native authorization-CAS and one-shot provider broker is not provisioned; mutating HTTP remains disabled.');
    }

    /**
     * Explicit deterministic offline test seam. Production probes cannot inject an
     * in-process authority; they require the native one-shot broker.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, string>  $trustedSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     */
    public static function claimAuthorizationSignaturesWithAuthorityNow(
        array $signedAuthorizations,
        DateTimeImmutable $runStartedAt,
        DateTimeImmutable $responseObservedAt,
        int $maximumAuthorizationTtlSeconds,
        int $maximumReceiptTtlSeconds,
        LiveEvidenceConsumptionAuthority $authority,
        string $claimNonce,
        array $trustedSigners,
        array $trustedConsumptionAuthorities,
    ): VerifiedFreshClaimGrant {
        self::assertProvisionedDisjointTrustRoles($trustedSigners, $trustedConsumptionAuthorities);

        foreach ($signedAuthorizations as $signedAuthorization) {
            self::assertAuthorizationSignature(
                $signedAuthorization,
                $runStartedAt,
                $maximumAuthorizationTtlSeconds,
                $trustedSigners,
            );
        }

        $claimRequest = self::buildConsumptionClaimRequest($signedAuthorizations, $runStartedAt, $claimNonce);
        $claimResult = $authority->claim(
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
        );

        if (! $claimResult instanceof FreshClaimGrant) {
            throw new InvalidArgumentException('A recovered consumption proof cannot unlock a mutating effect boundary.');
        }

        $signedReceipt = $claimResult->receipt->toArray();
        self::assertConsumptionAuthorityReceiptNow(
            $signedReceipt,
            $signedAuthorizations,
            $claimRequest,
            $responseObservedAt,
            $maximumReceiptTtlSeconds,
            $trustedConsumptionAuthorities,
        );

        return self::brandVerifiedFreshClaimGrant(
            $claimResult,
            'mock_test',
            $signedAuthorizations,
            ConsumptionClaimRequest::fromArray($claimRequest),
            $responseObservedAt,
        );
    }

    /**
     * Production effect-boundary revalidation. Only a grant already branded by the
     * direct remote-response verifier can reach this method.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    public static function assertVerifiedFreshGrantAtEffectBoundary(
        VerifiedFreshClaimGrant $grant,
        array $signedAuthorizations,
        ConsumptionClaimRequest $claimRequest,
        string $repositoryRoot,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
    ): VerifiedFreshClaimGrant {
        throw new RuntimeException('A CAS grant cannot open a mutating effect; a supervisor-signed brokered execution receipt is required.');
    }

    /**
     * Explicit deterministic counterpart for MockClient tests only.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, string>  $trustedOperatorSigners
     * @param  array<string, string>  $trustedConsumptionAuthorities
     */
    public static function assertVerifiedFreshGrantSignaturesAtEffectBoundary(
        VerifiedFreshClaimGrant $grant,
        array $signedAuthorizations,
        ConsumptionClaimRequest $claimRequest,
        DateTimeImmutable $now,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
        array $trustedOperatorSigners,
        array $trustedConsumptionAuthorities,
    ): VerifiedFreshClaimGrant {
        self::assertProvisionedDisjointTrustRoles($trustedOperatorSigners, $trustedConsumptionAuthorities);
        self::assertRegisteredVerifiedFreshGrant(
            $grant,
            'mock_test',
            $signedAuthorizations,
            $claimRequest,
            $now,
        );
        self::assertEffectBoundaryAuthorizations(
            $signedAuthorizations,
            $claimRequest,
            '',
            $now,
            $maximumAuthorizationTtlSeconds,
            $minimumAuthorizationRemainingSeconds,
            $trustedOperatorSigners,
            false,
        );
        self::assertConsumptionAuthorityReceiptNow(
            $grant->toArray(),
            $signedAuthorizations,
            $claimRequest->toArray(),
            $now,
            $maximumReceiptTtlSeconds,
            $trustedConsumptionAuthorities,
        );

        return $grant;
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, string>  $trustedOperatorSigners
     */
    private static function assertEffectBoundaryAuthorizations(
        array $signedAuthorizations,
        ConsumptionClaimRequest $claimRequest,
        string $repositoryRoot,
        DateTimeImmutable $now,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        array $trustedOperatorSigners,
        bool $verifyHarness,
    ): void {
        if ($minimumAuthorizationRemainingSeconds < 0 || $signedAuthorizations === []) {
            throw new InvalidArgumentException('The effect boundary authorization window is invalid.');
        }

        $envelopes = [];

        foreach ($signedAuthorizations as $signedAuthorization) {
            $envelope = self::assertAuthorizationNowWithSigners(
                $signedAuthorization,
                $now,
                $maximumAuthorizationTtlSeconds,
                $trustedOperatorSigners,
            );
            $expiresAt = self::strictUtcDate($envelope['expires_at']);

            if (self::instantMicroseconds($expiresAt) - self::instantMicroseconds($now) < $minimumAuthorizationRemainingSeconds * 1_000_000) {
                throw new InvalidArgumentException('A pre-run authorization expires before the next mutating request can be bounded.');
            }

            $envelopes[] = $envelope;
        }

        if ($verifyHarness) {
            self::assertHarnessMatchesRepositoryCommit(
                $repositoryRoot,
                $envelopes[0]['evidence_contract'],
                $envelopes[0]['harness']['repository_commit'],
                $envelopes[0]['harness']['code_sha256'],
            );
        }

        $expectedRequest = self::buildConsumptionClaimRequest(
            $signedAuthorizations,
            self::strictUtcDate($claimRequest->runStartedAt),
            $claimRequest->claimNonce,
        );

        if (! hash_equals(self::canonicalJson($claimRequest->toArray()), self::canonicalJson($expectedRequest))) {
            throw new InvalidArgumentException('The effect boundary authorizations do not bind the exact claimed batch.');
        }
    }

    /**
     * Explicit dependency-injected unit seam. Live probes must call claimAuthorizationsNow.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, string>  $trustedSigners
     * @param  (callable(string, int): void)|null  $afterClaim
     * @return array<string, mixed>
     */
    public static function claimAuthorizationSignaturesNow(
        array $signedAuthorizations,
        string $repositoryRoot,
        LiveEvidenceClaimStore $claimStore,
        DateTimeImmutable $now,
        int $maximumTtlSeconds,
        array $trustedSigners,
        ?callable $afterClaim = null,
    ): array {
        if ($trustedSigners === []) {
            throw new InvalidArgumentException('The claim test seam requires an explicit non-empty signer map.');
        }

        $envelopes = [];

        foreach ($signedAuthorizations as $signedAuthorization) {
            $envelopes[] = self::assertAuthorizationSignature(
                $signedAuthorization,
                $now,
                $maximumTtlSeconds,
                $trustedSigners,
            );
        }

        return self::claimValidatedAuthorizations(
            $signedAuthorizations,
            $envelopes,
            $repositoryRoot,
            $claimStore,
            $now,
            $afterClaim,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  list<array<string, mixed>>  $envelopes
     * @param  (callable(string, int): void)|null  $afterClaim
     * @return array<string, mixed>
     */
    private static function claimValidatedAuthorizations(
        array $signedAuthorizations,
        array $envelopes,
        string $repositoryRoot,
        LiveEvidenceClaimStore $claimStore,
        DateTimeImmutable $now,
        ?callable $afterClaim = null,
    ): array {
        if ($signedAuthorizations === [] || count($signedAuthorizations) !== count($envelopes)) {
            throw new InvalidArgumentException('At least one validated pre-run authorization must be consumed.');
        }

        $claimDirectory = self::secureOperatorClaimDirectory($claimStore->directory(), $repositoryRoot);
        $storeIdentitySha256 = self::claimStoreIdentitySha256($claimDirectory);
        $receipt = self::buildConsumptionReceipt($signedAuthorizations, $now);

        if (($receipt['store_identity_sha256'] ?? null) !== $storeIdentitySha256) {
            throw new InvalidArgumentException('The authorization is bound to a different canonical operator claim store.');
        }

        $lock = self::openOwnerOnlyClaimFile($claimDirectory.'/.claims.lock');

        if (! flock($lock, LOCK_EX)) {
            fclose($lock);

            throw new RuntimeException('Could not exclusively lock the live authorization claim store.');
        }

        $createdPaths = [];

        try {
            $existingClaims = self::assertExistingClaimRecordsAreSafe($claimDirectory);

            if (isset($existingClaims['run_ids'][$receipt['run_id']])) {
                throw new RuntimeException('The live authorization run was already consumed; only reconcile/read recovery is permitted.');
            }

            foreach ($envelopes as $index => $envelope) {
                $authorizationSha256 = self::signedDocumentSha256($signedAuthorizations[$index]);
                $challengeSha256 = hash('sha256', $envelope['challenge']);
                $claimPath = $claimDirectory.'/claim-'.$authorizationSha256.'-'.$challengeSha256.'.json';

                if (isset($existingClaims['challenge_hashes'][$challengeSha256])
                    || \file_exists($claimPath)
                    || \is_link($claimPath)) {
                    throw new RuntimeException('The live authorization challenge was already consumed; only reconcile/read recovery is permitted.');
                }

                self::writeExclusiveOwnerOnlyFile($claimPath, self::canonicalJson([
                    'contract' => 'cieplik206.fakturownia.authorization-claim-record',
                    'version' => self::Version,
                    'authorization_sha256' => $authorizationSha256,
                    'challenge_sha256' => $challengeSha256,
                    'receipt' => $receipt,
                ]));
                $createdPaths[] = $claimPath;
                self::durablySyncClaimStore();

                if ($afterClaim !== null) {
                    $afterClaim($claimPath, count($createdPaths));
                }
            }
        } catch (\Throwable $exception) {
            flock($lock, LOCK_UN);
            fclose($lock);

            if ($createdPaths !== []) {
                throw new RuntimeException(
                    'The authorization batch was durably claimed only in part; the entire run remains consumed for reconcile/read recovery only.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $receipt;
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @return array<string, mixed>
     */
    public static function buildConsumptionReceipt(array $signedAuthorizations, DateTimeImmutable $claimedAt): array
    {
        $rows = [];
        $storeIdentitySha256 = null;
        $runId = null;
        $harness = null;
        $latestIssuedAt = null;

        foreach ($signedAuthorizations as $signedAuthorization) {
            $authorization = $signedAuthorization['envelope'] ?? null;

            if (! is_array($authorization)) {
                throw new InvalidArgumentException('A consumption receipt authorization envelope is missing.');
            }

            self::assertAuthorizationEnvelopeShape($authorization);
            $issuedAt = self::strictUtcDate($authorization['issued_at']);
            $latestIssuedAt = max($latestIssuedAt ?? PHP_INT_MIN, self::instantMicroseconds($issuedAt));
            $consumption = $authorization['consumption'];

            if ($storeIdentitySha256 !== null && $storeIdentitySha256 !== $consumption['store_identity_sha256']
                || $runId !== null && $runId !== $consumption['run_id']
                || $harness !== null && $harness !== $authorization['harness']) {
                throw new InvalidArgumentException('Every authorization in a consumed batch must bind one ledger, run and harness.');
            }

            $storeIdentitySha256 = $consumption['store_identity_sha256'];
            $runId = $consumption['run_id'];
            $harness = $authorization['harness'];
            $rows[] = [
                'profile' => $authorization['target']['profile'],
                'authorization_sha256' => self::signedDocumentSha256($signedAuthorization),
                'challenge_sha256' => hash('sha256', $authorization['challenge']),
                'configuration_hmac_sha256' => $authorization['commitments']['configuration_hmac_sha256'],
            ];
        }

        if ($rows === [] || self::instantMicroseconds($claimedAt) < $latestIssuedAt) {
            throw new InvalidArgumentException('An authorization cannot be consumed before it is issued.');
        }

        usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);

        $profiles = array_column($rows, 'profile');
        $authorizationHashes = array_column($rows, 'authorization_sha256');
        $challengeHashes = array_column($rows, 'challenge_sha256');

        if (count($profiles) !== count(array_unique($profiles))
            || count($authorizationHashes) !== count(array_unique($authorizationHashes))
            || count($challengeHashes) !== count(array_unique($challengeHashes))) {
            throw new InvalidArgumentException('A consumed authorization batch contains a duplicate profile, document or challenge.');
        }

        $hash = static fn (array $value): string => hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.authorization-consumption-set',
            'version' => self::Version,
            'value' => $value,
        ]));

        return [
            'contract' => self::ConsumptionReceiptContract,
            'version' => self::Version,
            'store_identity_sha256' => $storeIdentitySha256,
            'run_id' => $runId,
            'claimed_at' => $claimedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'authorization_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['authorization_sha256'],
            ], $rows)),
            'challenge_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['challenge_sha256'],
            ], $rows)),
            'harness' => $harness,
            'configuration_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['configuration_hmac_sha256'],
            ], $rows)),
            'replay_policy' => self::ConsumptionReplayPolicy,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @return array{
     *     authority_id: string,
     *     authority_policy_sha256: string,
     *     store_id: string,
     *     store_identity_sha256: string,
     *     run_id: string,
     *     harness: array{repository_commit: string, code_sha256: string, launch_manifest_sha256: string},
     *     authorization_set_sha256: string,
     *     challenge_set_sha256: string,
     *     configuration_set_sha256: string
     * }
     */
    private static function authorizationConsumptionAggregate(array $signedAuthorizations): array
    {
        $rows = [];
        $authorityId = null;
        $authorityPolicySha256 = null;
        $storeId = null;
        $storeIdentitySha256 = null;
        $runId = null;
        $harness = null;

        foreach ($signedAuthorizations as $signedAuthorization) {
            $authorization = $signedAuthorization['envelope'] ?? null;

            if (! is_array($authorization)) {
                throw new InvalidArgumentException('A consumption claim authorization envelope is missing.');
            }

            self::assertAuthorizationEnvelopeShape($authorization);
            $consumption = $authorization['consumption'];

            if ($authorityId !== null && $authorityId !== $consumption['authority_id']
                || $authorityPolicySha256 !== null && $authorityPolicySha256 !== $consumption['authority_policy_sha256']
                || $storeId !== null && $storeId !== $consumption['store_id']
                || $storeIdentitySha256 !== null && $storeIdentitySha256 !== $consumption['store_identity_sha256']
                || $runId !== null && $runId !== $consumption['run_id']
                || $harness !== null && $harness !== $authorization['harness']) {
                throw new InvalidArgumentException('Every authorization must bind one external authority policy, store, run and harness.');
            }

            $authorityId = $consumption['authority_id'];
            $authorityPolicySha256 = $consumption['authority_policy_sha256'];
            $storeId = $consumption['store_id'];
            $storeIdentitySha256 = $consumption['store_identity_sha256'];
            $runId = $consumption['run_id'];
            $harness = $authorization['harness'];
            $rows[] = [
                'profile' => $authorization['target']['profile'],
                'authorization_sha256' => self::signedDocumentSha256($signedAuthorization),
                'challenge_sha256' => hash('sha256', $authorization['challenge']),
                'configuration_hmac_sha256' => $authorization['commitments']['configuration_hmac_sha256'],
            ];
        }

        if ($rows === []
            || ! is_string($authorityId)
            || ! is_string($authorityPolicySha256)
            || ! is_string($storeId)
            || ! is_string($storeIdentitySha256)
            || ! is_string($runId)
            || ! is_array($harness)) {
            throw new InvalidArgumentException('At least one complete pre-run authorization is required for an external claim.');
        }

        if (! is_string($harness['repository_commit'] ?? null)
            || ! is_string($harness['code_sha256'] ?? null)
            || ! is_string($harness['launch_manifest_sha256'] ?? null)) {
            throw new InvalidArgumentException('Every authorization must bind a complete harness identity.');
        }

        usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);
        $profiles = array_column($rows, 'profile');
        $authorizationHashes = array_column($rows, 'authorization_sha256');
        $challengeHashes = array_column($rows, 'challenge_sha256');

        if (count($profiles) !== count(array_unique($profiles))
            || count($authorizationHashes) !== count(array_unique($authorizationHashes))
            || count($challengeHashes) !== count(array_unique($challengeHashes))) {
            throw new InvalidArgumentException('An external claim contains a duplicate profile, authorization or challenge.');
        }

        $hash = static fn (array $value): string => hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.authorization-consumption-set',
            'version' => self::Version,
            'value' => $value,
        ]));

        return [
            'authority_id' => $authorityId,
            'authority_policy_sha256' => $authorityPolicySha256,
            'store_id' => $storeId,
            'store_identity_sha256' => $storeIdentitySha256,
            'run_id' => $runId,
            'harness' => $harness,
            'authorization_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['authorization_sha256'],
            ], $rows)),
            'challenge_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['challenge_sha256'],
            ], $rows)),
            'configuration_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['configuration_hmac_sha256'],
            ], $rows)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @return array<string, mixed>
     */
    public static function buildConsumptionClaimRequest(
        array $signedAuthorizations,
        DateTimeImmutable $runStartedAt,
        string $claimNonce,
    ): array {
        $aggregate = self::authorizationConsumptionAggregate($signedAuthorizations);
        self::assertCanonicalChallenge($claimNonce);

        $request = (new ConsumptionClaimRequest(
            $aggregate['authority_id'],
            $aggregate['authority_policy_sha256'],
            $aggregate['store_id'],
            $aggregate['store_identity_sha256'],
            $aggregate['run_id'],
            $runStartedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            $claimNonce,
            $aggregate['harness'],
            $aggregate['authorization_set_sha256'],
            $aggregate['challenge_set_sha256'],
            $aggregate['configuration_set_sha256'],
        ))->toArray();
        self::assertConsumptionClaimRequestShape($request);

        return $request;
    }

    /**
     * Deterministic unit seam for a receipt returned directly by a separately trusted,
     * atomic CAS/append-only authority. Production runners must never synthesize it.
     *
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $claimRequest
     * @param  array<string, mixed>  $claimCursor
     * @return array<string, mixed>
     */
    public static function buildConsumptionAuthorityEnvelopeForTesting(
        array $signedAuthorizations,
        array $claimRequest,
        string $authoritySignerId,
        array $claimCursor,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        string $disposition = self::FreshConsumptionDisposition,
    ): array {
        $expectedRequest = self::buildConsumptionClaimRequest(
            $signedAuthorizations,
            self::strictUtcDate((string) ($claimRequest['run_started_at'] ?? '')),
            (string) ($claimRequest['claim_nonce'] ?? ''),
        );

        if (! hash_equals(self::canonicalJson($claimRequest), self::canonicalJson($expectedRequest))) {
            throw new InvalidArgumentException('The external consumption authority claim inputs are invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $receiptDisposition = ConsumptionDisposition::tryFrom($disposition);

        if (! $receiptDisposition instanceof ConsumptionDisposition) {
            throw new InvalidArgumentException('The external consumption authority disposition is invalid.');
        }

        return (new ConsumptionReceiptEnvelope(
            $authoritySignerId,
            $issuedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $expiresAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            ClaimCursor::fromArray($claimCursor),
            $receiptDisposition,
            ConsumptionClaimRequest::fromArray($claimRequest),
        ))->toArray();
    }

    /**
     * @param  array<string, mixed>  $signedReceipt
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $expectedClaimRequest
     * @param  array<string, string>  $trustedAuthorities
     * @return array<string, mixed>
     */
    private static function assertConsumptionAuthorityReceiptNow(
        array $signedReceipt,
        array $signedAuthorizations,
        array $expectedClaimRequest,
        DateTimeImmutable $responseObservedAt,
        int $maximumTtlSeconds,
        array $trustedAuthorities,
    ): array {
        $envelope = self::assertConsumptionAuthorityReceiptSignature(
            $signedReceipt,
            $signedAuthorizations,
            $expectedClaimRequest,
            $maximumTtlSeconds,
            $trustedAuthorities,
        );
        $issuedAt = self::strictUtcDate($envelope['issued_at']);
        $expiresAt = self::strictUtcDate($envelope['expires_at']);
        $runStartedAt = self::strictUtcDate($expectedClaimRequest['run_started_at']);

        if (self::instantMicroseconds($issuedAt) < self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds($issuedAt) > self::instantMicroseconds($responseObservedAt)
            || self::instantMicroseconds($expiresAt) <= self::instantMicroseconds($responseObservedAt)
            || $envelope['disposition'] !== self::FreshConsumptionDisposition) {
            throw new InvalidArgumentException('The external authority receipt was not freshly returned for this exact in-memory run.');
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $signedReceipt
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $expectedClaimRequest
     * @param  array<string, string>  $trustedAuthorities
     * @return array<string, mixed>
     */
    private static function assertConsumptionAuthorityReceiptSignature(
        array $signedReceipt,
        array $signedAuthorizations,
        array $expectedClaimRequest,
        int $maximumTtlSeconds,
        array $trustedAuthorities,
    ): array {
        if ($trustedAuthorities === []) {
            throw new InvalidArgumentException('No separately trusted external consumption authority is provisioned.');
        }

        [$envelope] = self::assertTrustedSignature(
            $signedReceipt,
            self::ConsumptionReceiptContract,
            $maximumTtlSeconds,
            $trustedAuthorities,
        );
        self::assertConsumptionAuthorityEnvelopeShape($envelope);
        $expected = self::buildConsumptionAuthorityEnvelopeForTesting(
            $signedAuthorizations,
            $expectedClaimRequest,
            $envelope['signer_id'],
            $envelope['claim_cursor'],
            self::strictUtcDate($envelope['issued_at']),
            self::strictUtcDate($envelope['expires_at']),
            $envelope['disposition'],
        );

        if (! hash_equals(self::canonicalJson($envelope), self::canonicalJson($expected))) {
            throw new InvalidArgumentException('The external consumption authority receipt does not bind the exact fresh claim request.');
        }

        return $envelope;
    }

    /** @param array<string, mixed> $request */
    private static function assertConsumptionClaimRequestShape(array $request): void
    {
        ConsumptionClaimRequest::fromArray($request);
    }

    /** @param array<string, mixed> $envelope */
    private static function assertConsumptionAuthorityEnvelopeShape(array $envelope): void
    {
        ConsumptionReceiptEnvelope::fromArray($envelope);
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalUnsignedEvidencePayload(array $payload): string
    {
        self::assertEvidencePayloadShape($payload);

        return self::canonicalJson($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, mixed>  $fixture
     * @return array<string, string>
     */
    public static function evidenceCommitments(
        array $signedAuthorizations,
        array $fixture,
        string $evidenceContract,
    ): array {
        $rows = [];

        foreach ($signedAuthorizations as $signedAuthorization) {
            $authorization = $signedAuthorization['envelope'] ?? null;

            if (! is_array($authorization)) {
                throw new InvalidArgumentException('An evidence commitment authorization envelope is missing.');
            }

            self::assertAuthorizationEnvelopeShape($authorization);

            if ($authorization['evidence_contract'] !== $evidenceContract) {
                throw new InvalidArgumentException('An authorization belongs to a different evidence contract.');
            }

            $rows[] = [
                'profile' => $authorization['target']['profile'],
                'target' => $authorization['target'],
                'configuration' => $authorization['commitments']['configuration_hmac_sha256'],
                'policy' => $authorization['commitments']['policy_hmac_sha256'],
                'safety' => $authorization['commitments']['safety_hmac_sha256'],
                'templates' => $authorization['commitments']['templates_hmac_sha256'],
                'limits' => $authorization['limits'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);

        if ($rows === []) {
            throw new InvalidArgumentException('Evidence commitments require at least one pre-run authorization.');
        }

        $fixturePolicy = match ($evidenceContract) {
            'fakturownia-invoice-identity-s0.3-v1' => [
                'vat_fixture_evidence' => $fixture['vat_fixture_evidence'] ?? null,
                'vat_pilot_policy' => $fixture['vat_pilot_policy'] ?? null,
            ],
            'fakturownia-ksef-demo-s0.4-v1' => [
                'capability_0_2' => $fixture['capability_0_2'] ?? null,
            ],
            default => throw new InvalidArgumentException('The evidence contract has no fixture policy commitment mapping.'),
        };

        foreach ($fixturePolicy as $policyValue) {
            if (! is_array($policyValue)) {
                throw new InvalidArgumentException('The sanitized fixture is missing its committed policy evidence.');
            }
        }

        $hash = static fn (array $value): string => hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.evidence-commitment',
            'version' => self::Version,
            'value' => $value,
        ]));

        return [
            'scheme' => self::EvidenceCommitmentScheme,
            'target_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'target' => $row['target'],
            ], $rows)),
            'configuration_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'value' => $row['configuration'],
            ], $rows)),
            'policy_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'value' => $row['policy'],
            ], $rows)),
            'safety_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'value' => $row['safety'],
            ], $rows)),
            'templates_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'value' => $row['templates'],
            ], $rows)),
            'limits_set_sha256' => $hash(array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'value' => $row['limits'],
            ], $rows)),
            'fixture_policy_sha256' => $hash($fixturePolicy),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @param  array<string, string>|null  $trustedSigners
     * @param  array<string, string>|null  $trustedConsumptionAuthorities
     * @param  (callable(string, int): void)|null  $afterWrite
     * @return array{unsigned_path: string, authorization_paths: list<string>}
     */
    public static function writeUnsignedEvidenceSidecar(
        string $repositoryRoot,
        array $payload,
        array $signedAuthorizations,
        int $maximumAuthorizationTtlSeconds,
        ?array $trustedSigners = null,
        ?array $trustedConsumptionAuthorities = null,
        ?callable $afterWrite = null,
    ): array {
        throw new InvalidArgumentException('The legacy/test evidence writer cannot publish a canonical live sidecar.');
    }

    /**
     * Production-only publisher. Trust anchors and clocks remain pinned/internal;
     * deterministic mock tokens cannot cross this boundary.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $signedAuthorizations
     * @return array{unsigned_path: string, authorization_paths: list<string>}
     */
    public static function writeLiveUnsignedEvidenceSidecar(
        string $repositoryRoot,
        array $payload,
        array $signedAuthorizations,
        VerifiedFreshClaimGrant $authorityGrant,
        VerifiedLiveProviderRun $providerRun,
        int $maximumAuthorizationTtlSeconds,
    ): array {
        self::assertLivePayloadMatchesRuntimeBrands(
            $payload,
            $signedAuthorizations,
            $authorityGrant,
            $providerRun,
        );

        throw new RuntimeException('Canonical live sidecar publication requires supervisor-signed brokered effect-execution receipts.');
    }

    /** @return list<string> */
    public static function harnessManifest(string $evidenceContract): array
    {
        $paths = self::EvidenceHarnessFiles[$evidenceContract] ?? null;

        if ($paths === null) {
            throw new InvalidArgumentException('The evidence contract has no allowlisted harness manifest.');
        }

        return $paths;
    }

    public static function harnessCodeSha256(string $repositoryRoot, string $evidenceContract): string
    {
        return hash('sha256', self::canonicalJson(self::harnessSnapshot($repositoryRoot, $evidenceContract)));
    }

    /**
     * Sanitized manifest archived in the post-run result. Historical verification checks
     * this signed snapshot and Git objects, never the verifier's current PHP/vendor tree.
     *
     * @return array<string, mixed>
     */
    public static function harnessSnapshot(string $repositoryRoot, string $evidenceContract): array
    {
        $files = [];

        foreach (self::harnessManifest($evidenceContract) as $relativePath) {
            $contents = self::readRepositoryFile($repositoryRoot, $relativePath);
            $files[] = ['path' => $relativePath, 'sha256' => hash('sha256', $contents)];
        }

        $snapshot = [
            'contract' => 'cieplik206.fakturownia.harness-manifest-digest',
            'version' => self::Version,
            'evidence_contract' => $evidenceContract,
            'files' => $files,
            'dependencies' => self::dependencyTreeSnapshot($repositoryRoot),
            'runtime' => self::runtimeSnapshot(),
        ];
        self::assertArchivedHarnessSnapshotShape($snapshot);

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private static function dependencyTreeSnapshot(string $repositoryRoot): array
    {
        self::assertExactComposerBootstrapFiles($repositoryRoot);

        if (! class_exists(InstalledVersions::class)
            || ! class_exists(VersionParser::class)) {
            throw new RuntimeException('Composer dependency integrity metadata is unavailable.');
        }

        try {
            $lock = json_decode(self::readRepositoryFile($repositoryRoot, 'composer.lock'), true, flags: JSON_THROW_ON_ERROR);
            $rootPackage = json_decode(self::readRepositoryFile($repositoryRoot, 'composer.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The tracked Composer lock is invalid.', previous: $exception);
        }

        if (! is_array($lock)
            || ! is_array($lock['packages'] ?? null)
            || ! is_array($lock['packages-dev'] ?? null)
            || ! is_array($rootPackage)
            || ! is_string($rootPackage['name'] ?? null)) {
            throw new RuntimeException('The tracked Composer lock has an invalid dependency contract.');
        }

        $lockedPackages = [...$lock['packages'], ...$lock['packages-dev']];
        usort($lockedPackages, static fn (array $left, array $right): int => ($left['name'] ?? '') <=> ($right['name'] ?? ''));
        $expectedInstalledNames = [...array_column($lockedPackages, 'name'), $rootPackage['name']];
        sort($expectedInstalledNames);
        $actualInstalledNames = [];

        $installedSets = self::installedVersionSets();

        if (! is_array($installedSets)) {
            throw new RuntimeException('Composer dependency integrity metadata is invalid.');
        }

        foreach ($installedSets as $installedSet) {
            if (! is_array($installedSet) || ! is_array($installedSet['versions'] ?? null)) {
                throw new RuntimeException('Composer dependency integrity metadata is invalid.');
            }

            foreach ($installedSet['versions'] as $installedName => $installedMetadata) {
                if (is_string($installedName)
                    && is_array($installedMetadata)
                    && ($installedMetadata['install_path'] ?? null) !== null) {
                    $actualInstalledNames[$installedName] = true;
                }
            }
        }

        $actualInstalledNames = array_keys($actualInstalledNames);
        sort($actualInstalledNames);

        if ($actualInstalledNames !== $expectedInstalledNames) {
            throw new RuntimeException('The exact physically installed package set does not match the tracked Composer lock and root package.');
        }

        $versionParser = new VersionParser;
        $snapshot = [];

        foreach ($lockedPackages as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (! is_string($name)
                || ! is_string($version)
                || ! InstalledVersions::isInstalled($name)) {
                throw new RuntimeException('The installed dependency tree does not match the tracked Composer lock.');
            }

            $installedVersion = InstalledVersions::getPrettyVersion($name);

            try {
                $versionsMatch = is_string($installedVersion)
                    && $versionParser->normalize($installedVersion) === $versionParser->normalize($version);
            } catch (\UnexpectedValueException) {
                $versionsMatch = $installedVersion === $version;
            }

            $expectedReference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
            $installedReference = InstalledVersions::getReference($name);

            if (! $versionsMatch
                || is_string($expectedReference) && $expectedReference !== '' && $installedReference !== $expectedReference) {
                throw new RuntimeException('An installed dependency version or reference differs from the tracked Composer lock.');
            }

            $installPath = InstalledVersions::getInstallPath($name);
            $snapshot[] = [
                'name' => $name,
                'version' => $version,
                'reference' => $installedReference,
                'tree_sha256' => $installPath === null ? null : self::installedPackageTreeSha256($installPath),
            ];
        }

        $bootstrap = [];

        foreach (self::ComposerBootstrapFiles as $relativePath) {
            $contents = self::readRepositoryFile($repositoryRoot, $relativePath);
            $bootstrap[] = [
                'path' => $relativePath,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        return [
            'lock_sha256' => hash('sha256', self::readRepositoryFile($repositoryRoot, 'composer.lock')),
            'packages' => $snapshot,
            'bootstrap' => $bootstrap,
        ];
    }

    /** @return list<string> */
    public static function dependencyBootstrapManifest(): array
    {
        return self::ComposerBootstrapFiles;
    }

    private static function assertExactComposerBootstrapFiles(string $repositoryRoot): void
    {
        $root = realpath($repositoryRoot);
        $composerDirectory = $root === false ? false : realpath($root.'/vendor/composer');

        if ($root === false
            || $composerDirectory === false
            || is_link($root.'/vendor')
            || is_link($root.'/vendor/composer')) {
            throw new RuntimeException('The Composer bootstrap directory is missing or unsafe.');
        }

        $actual = ['vendor/autoload.php'];
        $iterator = new \FilesystemIterator($composerDirectory, \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                throw new RuntimeException('The Composer bootstrap contains an unreadable entry.');
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException('The Composer bootstrap contains an unsafe PHP entry.');
            }

            $actual[] = 'vendor/composer/'.$file->getFilename();
        }

        sort($actual);
        $expected = self::ComposerBootstrapFiles;
        sort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException('The Composer bootstrap PHP set contains an unexpected or missing executable file.');
        }
    }

    private static function installedPackageTreeSha256(string $installPath): string
    {
        $root = realpath($installPath);

        if ($root === false || ! is_dir($root) || is_link($installPath)) {
            throw new RuntimeException('An installed dependency path is missing or unsafe.');
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isLink()) {
                $metadata = lstat($path);
                $target = readlink($path);

                $targetPath = is_string($target) && str_starts_with($target, DIRECTORY_SEPARATOR)
                    ? $target
                    : dirname($path).DIRECTORY_SEPARATOR.$target;
                $resolvedTarget = $target === false ? false : realpath($targetPath);
                $composerVendorRoot = realpath(dirname(__DIR__, 3).'/vendor');

                if ($target === false
                    || str_contains($target, "\0")
                    || $resolvedTarget === false
                    || $composerVendorRoot === false
                    || $resolvedTarget !== $composerVendorRoot
                    || $metadata === false) {
                    throw new RuntimeException('An installed dependency contains an unsafe symlink.');
                }

                $files[] = [
                    'path' => substr($path, strlen($root) + 1),
                    'type' => 'symlink',
                    'mode' => $metadata['mode'] & 0777,
                    'size' => strlen($target),
                    'sha256' => hash('sha256', 'symlink:'.$target),
                ];

                continue;
            }

            if ($file->isDir()) {
                $metadata = lstat($path);

                if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000) {
                    throw new RuntimeException('An installed dependency contains an unsafe directory.');
                }

                $files[] = [
                    'path' => substr($path, strlen($root) + 1),
                    'type' => 'directory',
                    'mode' => $metadata['mode'] & 0777,
                    'size' => 0,
                    'sha256' => hash('sha256', ''),
                ];

                continue;
            }

            if (! $file->isFile()) {
                throw new RuntimeException('An installed dependency contains a special filesystem entry.');
            }

            $metadata = lstat($path);
            $handle = fopen($path, 'rb');

            if ($metadata === false || $handle === false) {
                throw new RuntimeException('An installed dependency file cannot be opened safely.');
            }

            try {
                $opened = fstat($handle);
                $contents = stream_get_contents($handle);
                $finished = fstat($handle);
                $current = lstat($path);
            } finally {
                fclose($handle);
            }

            if ($opened === false
                || $contents === false
                || $finished === false
                || $current === false
                || $opened['nlink'] !== 1
                || $finished['nlink'] !== 1
                || $current['nlink'] !== 1
                || $metadata['dev'] !== $opened['dev']
                || $metadata['ino'] !== $opened['ino']
                || $opened['dev'] !== $finished['dev']
                || $opened['ino'] !== $finished['ino']
                || $opened['dev'] !== $current['dev']
                || $opened['ino'] !== $current['ino']
                || strlen($contents) !== $opened['size']) {
                throw new RuntimeException('An installed dependency file cannot be integrity-pinned safely.');
            }

            $files[] = [
                'path' => substr($path, strlen($root) + 1),
                'type' => 'file',
                'mode' => $opened['mode'] & 0777,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.installed-package-tree',
            'version' => self::Version,
            'files' => $files,
        ]));
    }

    /** @return array<string, mixed> */
    private static function runtimeSnapshot(): array
    {
        if ((string) ini_get('auto_prepend_file') !== ''
            || (string) ini_get('auto_append_file') !== ''
            || getenv('LD_PRELOAD') !== false
            || getenv('DYLD_INSERT_LIBRARIES') !== false
            || getenv('BASH_ENV') !== false
            || getenv('ENV') !== false) {
            throw new RuntimeException('The live evidence runtime contains an untrusted bootstrap injection.');
        }

        $binary = realpath(PHP_BINARY);

        if ($binary === false || is_link(PHP_BINARY) || ! is_file($binary)) {
            throw new RuntimeException('The PHP runtime executable cannot be integrity-pinned.');
        }

        $iniPaths = [];
        $loadedIni = self::loadedIniFile();

        if (is_string($loadedIni) && $loadedIni !== '') {
            $iniPaths[] = $loadedIni;
        }

        $scanned = php_ini_scanned_files();

        if (is_string($scanned)) {
            foreach (preg_split('/,\s*/', trim($scanned)) ?: [] as $iniPath) {
                if ($iniPath !== '') {
                    $iniPaths[] = $iniPath;
                }
            }
        }

        $iniFiles = [];

        foreach (array_values(array_unique($iniPaths)) as $iniPath) {
            $resolved = realpath($iniPath);
            $contents = $resolved === false || is_link($iniPath) ? false : file_get_contents($resolved);

            if ($resolved === false || $contents === false || ! is_file($resolved)) {
                throw new RuntimeException('A loaded PHP configuration file cannot be integrity-pinned.');
            }

            $iniFiles[] = ['path_sha256' => hash('sha256', $resolved), 'sha256' => hash('sha256', $contents)];
        }

        usort($iniFiles, static fn (array $left, array $right): int => $left['path_sha256'] <=> $right['path_sha256']);
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'php_binary_sha256' => hash_file('sha256', $binary),
            'git_binary_sha256' => self::trustedExecutableSha256(self::GitBinary),
            'sync_binary_sha256' => self::trustedExecutableSha256(self::SyncBinary),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'ini_files' => $iniFiles,
            'extensions' => array_map(static fn (string $extension): array => [
                'name' => $extension,
                'version' => phpversion($extension) ?: null,
            ], $extensions),
        ];
    }

    private static function trustedExecutableSha256(string $path): string
    {
        $resolved = realpath($path);
        $metadata = $resolved === false ? false : lstat($resolved);

        if ($resolved === false
            || $resolved !== $path
            || is_link($path)
            || ! is_file($resolved)
            || ! is_executable($resolved)
            || $metadata === false
            || $metadata['uid'] !== 0
            || ($metadata['mode'] & 0022) !== 0) {
            throw new RuntimeException('A security-critical executable cannot be integrity-pinned.');
        }

        $sha256 = hash_file('sha256', $resolved);

        if (! is_string($sha256)) {
            throw new RuntimeException('A security-critical executable cannot be read for integrity pinning.');
        }

        return $sha256;
    }

    public static function assertHarnessMatchesRepositoryCommit(
        string $repositoryRoot,
        string $evidenceContract,
        string $repositoryCommit,
        string $expectedCodeSha256,
    ): void {
        if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $repositoryCommit) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedCodeSha256) !== 1) {
            throw new InvalidArgumentException('The signed harness repository commit or digest is invalid.');
        }

        $root = realpath($repositoryRoot);

        if ($root === false || ! is_dir($root) || is_link($repositoryRoot)) {
            throw new RuntimeException('The harness repository root is invalid.');
        }

        self::runGit($root, ['cat-file', '-e', $repositoryCommit.'^{commit}']);
        $paths = self::harnessManifest($evidenceContract);
        $status = self::runGit($root, ['status', '--porcelain=v1', '--untracked-files=all', '--', ...$paths]);

        if ($status !== '') {
            throw new RuntimeException('The behavior-bearing harness files contain staged, unstaged, or untracked changes.');
        }

        foreach ($paths as $relativePath) {
            $currentContents = self::readRepositoryFile($root, $relativePath);
            $committedContents = self::runGit($root, ['show', $repositoryCommit.':'.$relativePath], false);

            if (! hash_equals($currentContents, $committedContents)) {
                throw new RuntimeException('A behavior-bearing harness file does not match the signed repository commit.');
            }
        }

        if (! hash_equals($expectedCodeSha256, self::harnessCodeSha256($root, $evidenceContract))) {
            throw new RuntimeException('The signed harness digest does not match the allowlisted behavior-bearing files.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public static function assertArchivedHarnessMatchesRepositoryCommit(
        string $repositoryRoot,
        string $evidenceContract,
        string $repositoryCommit,
        string $expectedCodeSha256,
        array $snapshot,
    ): void {
        self::assertArchivedHarnessSnapshotShape($snapshot);

        if (($snapshot['evidence_contract'] ?? null) !== $evidenceContract
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $repositoryCommit) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedCodeSha256) !== 1
            || ! hash_equals($expectedCodeSha256, hash('sha256', self::canonicalJson($snapshot)))) {
            throw new InvalidArgumentException('The archived harness snapshot does not match its signed provenance.');
        }

        $root = realpath($repositoryRoot);

        if ($root === false || ! is_dir($root) || is_link($repositoryRoot)) {
            throw new RuntimeException('The historical harness repository root is invalid.');
        }

        self::runGit($root, ['cat-file', '-e', $repositoryCommit.'^{commit}']);

        foreach ($snapshot['files'] as $file) {
            $committedContents = self::runGit($root, ['show', $repositoryCommit.':'.$file['path']], false);

            if (! hash_equals($file['sha256'], hash('sha256', $committedContents))) {
                throw new RuntimeException('An archived behavior-bearing file does not match its recorded Git object.');
            }
        }

        $lockedContents = self::runGit($root, ['show', $repositoryCommit.':composer.lock'], false);

        if (! hash_equals($snapshot['dependencies']['lock_sha256'], hash('sha256', $lockedContents))) {
            throw new RuntimeException('The archived dependency snapshot does not match the tracked Composer lock Git object.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    private static function assertArchivedHarnessSnapshotShape(array $snapshot): void
    {
        if (! self::hasExactKeys($snapshot, ['contract', 'version', 'evidence_contract', 'files', 'dependencies', 'runtime'])
            || ($snapshot['contract'] ?? null) !== 'cieplik206.fakturownia.harness-manifest-digest'
            || ($snapshot['version'] ?? null) !== self::Version
            || ! is_string($snapshot['evidence_contract'] ?? null)
            || ! is_array($snapshot['files'] ?? null)
            || ! array_is_list($snapshot['files'])
            || ! is_array($snapshot['dependencies'] ?? null)
            || ! self::hasExactKeys($snapshot['dependencies'], ['lock_sha256', 'packages', 'bootstrap'])
            || ! is_string($snapshot['dependencies']['lock_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $snapshot['dependencies']['lock_sha256']) !== 1
            || ! is_array($snapshot['dependencies']['packages'] ?? null)
            || ! array_is_list($snapshot['dependencies']['packages'])
            || ! is_array($snapshot['dependencies']['bootstrap'] ?? null)
            || ! array_is_list($snapshot['dependencies']['bootstrap'])
            || ! is_array($snapshot['runtime'] ?? null)
            || ! self::hasExactKeys($snapshot['runtime'], [
                'php_binary_sha256',
                'git_binary_sha256',
                'sync_binary_sha256',
                'php_version',
                'php_sapi',
                'ini_files',
                'extensions',
            ])) {
            throw new InvalidArgumentException('The archived harness snapshot has an invalid exact contract.');
        }

        $expectedPaths = self::harnessManifest($snapshot['evidence_contract']);
        $actualPaths = [];

        foreach ($snapshot['files'] as $file) {
            if (! is_array($file)
                || ! self::hasExactKeys($file, ['path', 'sha256'])
                || ! is_string($file['path'] ?? null)
                || ! is_string($file['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $file['sha256']) !== 1) {
                throw new InvalidArgumentException('The archived harness file manifest is invalid.');
            }

            $actualPaths[] = $file['path'];
        }

        if ($actualPaths !== $expectedPaths || count($actualPaths) !== count(array_unique($actualPaths))) {
            throw new InvalidArgumentException('The archived harness file manifest is incomplete or reordered.');
        }

        $packageNames = [];

        foreach ($snapshot['dependencies']['packages'] as $package) {
            if (! is_array($package)
                || ! self::hasExactKeys($package, ['name', 'version', 'reference', 'tree_sha256'])
                || ! is_string($package['name'] ?? null)
                || preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $package['name']) !== 1
                || ! is_string($package['version'] ?? null)
                || ! (is_string($package['reference'] ?? null) || ($package['reference'] ?? null) === null)
                || ! (is_string($package['tree_sha256'] ?? null) || ($package['tree_sha256'] ?? null) === null)
                || is_string($package['tree_sha256']) && preg_match('/^[a-f0-9]{64}$/', $package['tree_sha256']) !== 1) {
                throw new InvalidArgumentException('The archived Composer dependency entry is invalid.');
            }

            $packageNames[] = $package['name'];
        }

        $sortedPackageNames = $packageNames;
        sort($sortedPackageNames);

        if ($packageNames !== $sortedPackageNames || count($packageNames) !== count(array_unique($packageNames))) {
            throw new InvalidArgumentException('The archived Composer dependency list is not canonical.');
        }

        $bootstrapPaths = [];

        foreach ($snapshot['dependencies']['bootstrap'] as $bootstrapFile) {
            if (! is_array($bootstrapFile)
                || ! self::hasExactKeys($bootstrapFile, ['path', 'size', 'sha256'])
                || ! is_string($bootstrapFile['path'] ?? null)
                || ! is_int($bootstrapFile['size'] ?? null)
                || $bootstrapFile['size'] < 1
                || ! is_string($bootstrapFile['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $bootstrapFile['sha256']) !== 1) {
                throw new InvalidArgumentException('The archived Composer bootstrap manifest is invalid.');
            }

            $bootstrapPaths[] = $bootstrapFile['path'];
        }

        if ($bootstrapPaths !== self::ComposerBootstrapFiles) {
            throw new InvalidArgumentException('The archived Composer bootstrap manifest is incomplete or reordered.');
        }

        $runtime = $snapshot['runtime'];

        foreach (['php_binary_sha256', 'git_binary_sha256', 'sync_binary_sha256'] as $key) {
            if (! is_string($runtime[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/', $runtime[$key]) !== 1) {
                throw new InvalidArgumentException('The archived runtime executable digest is invalid.');
            }
        }

        if (! is_string($runtime['php_version'] ?? null)
            || ! is_string($runtime['php_sapi'] ?? null)
            || ! is_array($runtime['ini_files'] ?? null)
            || ! array_is_list($runtime['ini_files'])
            || ! is_array($runtime['extensions'] ?? null)
            || ! array_is_list($runtime['extensions'])) {
            throw new InvalidArgumentException('The archived runtime metadata is invalid.');
        }

        $iniPaths = [];

        foreach ($runtime['ini_files'] as $iniFile) {
            if (! is_array($iniFile)
                || ! self::hasExactKeys($iniFile, ['path_sha256', 'sha256'])
                || ! is_string($iniFile['path_sha256'] ?? null)
                || ! is_string($iniFile['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $iniFile['path_sha256']) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $iniFile['sha256']) !== 1) {
                throw new InvalidArgumentException('The archived runtime INI manifest is invalid.');
            }

            $iniPaths[] = $iniFile['path_sha256'];
        }

        $extensionNames = [];

        foreach ($runtime['extensions'] as $extension) {
            if (! is_array($extension)
                || ! self::hasExactKeys($extension, ['name', 'version'])
                || ! is_string($extension['name'] ?? null)
                || ! (is_string($extension['version'] ?? null) || ($extension['version'] ?? null) === null)) {
                throw new InvalidArgumentException('The archived runtime extension manifest is invalid.');
            }

            $extensionNames[] = $extension['name'];
        }

        $sortedIniPaths = $iniPaths;
        sort($sortedIniPaths);
        $sortedExtensionNames = $extensionNames;
        sort($sortedExtensionNames);

        if ($iniPaths !== $sortedIniPaths
            || count($iniPaths) !== count(array_unique($iniPaths))
            || $extensionNames !== $sortedExtensionNames
            || count($extensionNames) !== count(array_unique($extensionNames))) {
            throw new InvalidArgumentException('The archived runtime manifest is not canonical.');
        }
    }

    /** @param array<string, mixed> $envelope */
    private static function assertAuthorizationEnvelopeShape(array $envelope): void
    {
        if (! self::hasExactKeys($envelope, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'evidence_contract',
            'challenge',
            'harness',
            'target',
            'commitments',
            'consumption',
            'limits',
        ])
            || ($envelope['contract'] ?? null) !== self::AuthorizationContract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! is_string($envelope['evidence_contract'] ?? null)
            || ! is_string($envelope['challenge'] ?? null)
            || ! is_array($envelope['harness'] ?? null)
            || ! self::hasExactKeys($envelope['harness'], ['repository_commit', 'code_sha256', 'launch_manifest_sha256'])
            || ! is_string($envelope['harness']['repository_commit'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $envelope['harness']['repository_commit']) !== 1
            || ! is_string($envelope['harness']['code_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['harness']['code_sha256']) !== 1
            || ! is_string($envelope['harness']['launch_manifest_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['harness']['launch_manifest_sha256']) !== 1
            || ! is_array($envelope['target'] ?? null)
            || ! self::hasExactKeys($envelope['target'], [
                'environment',
                'profile',
                'tenant_hmac_sha256',
                'account_hmac_sha256',
            ])
            || ! is_string($envelope['target']['environment'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $envelope['target']['environment']) !== 1
            || ! is_string($envelope['target']['profile'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $envelope['target']['profile']) !== 1
            || ! is_array($envelope['commitments'] ?? null)
            || ! self::hasExactKeys($envelope['commitments'], [
                'scheme',
                'configuration_hmac_sha256',
                'policy_hmac_sha256',
                'safety_hmac_sha256',
                'templates_hmac_sha256',
            ])
            || ($envelope['commitments']['scheme'] ?? null) !== self::CommitmentScheme
            || ! is_array($envelope['consumption'] ?? null)
            || ! self::hasExactKeys($envelope['consumption'], ['authority_id', 'authority_policy_sha256', 'store_id', 'store_identity_sha256', 'run_id', 'replay_policy'])
            || ! is_string($envelope['consumption']['authority_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $envelope['consumption']['authority_id']) !== 1
            || ! is_string($envelope['consumption']['authority_policy_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['consumption']['authority_policy_sha256']) !== 1
            || ! is_string($envelope['consumption']['store_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $envelope['consumption']['store_id']) !== 1
            || ! is_string($envelope['consumption']['store_identity_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['consumption']['store_identity_sha256']) !== 1
            || ! is_string($envelope['consumption']['run_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $envelope['consumption']['run_id']) !== 1
            || ($envelope['consumption']['replay_policy'] ?? null) !== self::ConsumptionReplayPolicy
            || ! is_array($envelope['limits'] ?? null)
            || $envelope['limits'] === []
            || array_is_list($envelope['limits'])) {
            throw new InvalidArgumentException('The pre-run authorization envelope has an invalid exact contract.');
        }

        self::harnessManifest($envelope['evidence_contract']);
        self::assertCanonicalChallenge($envelope['challenge']);

        foreach (['tenant_hmac_sha256', 'account_hmac_sha256'] as $key) {
            if (! is_string($envelope['target'][$key] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $envelope['target'][$key]) !== 1) {
                throw new InvalidArgumentException('The pre-run authorization target commitments are invalid.');
            }
        }

        foreach (array_diff_key($envelope['commitments'], ['scheme' => true]) as $commitment) {
            if (! is_string($commitment) || preg_match('/^[a-f0-9]{64}$/', $commitment) !== 1) {
                throw new InvalidArgumentException('The pre-run authorization commitments are invalid.');
            }
        }

        self::canonicalJson($envelope['limits']);
    }

    /** @param array<string, mixed> $payload */
    private static function assertEvidencePayloadShape(array $payload): void
    {
        $isLive = ($payload['contract'] ?? null) === self::EvidencePayloadContract;
        $isTest = ($payload['contract'] ?? null) === self::TestEvidencePayloadContract;
        $expectedKeys = [
            'contract',
            'version',
            'evidence',
            'probe',
            'run',
            'consumption',
            'authorizations',
            'commitments',
        ];

        if ($isLive) {
            $expectedKeys[] = 'origins';
        }

        if ((! $isLive && ! $isTest)
            || ! self::hasExactKeys($payload, $expectedKeys)
            || ($payload['version'] ?? null) !== self::Version
            || ! is_array($payload['run'] ?? null)
            || ! is_string($payload['run']['finished_at'] ?? null)) {
            throw new InvalidArgumentException('The unsigned post-run evidence payload has an invalid exact contract.');
        }

        $finishedAt = self::strictUtcDate($payload['run']['finished_at']);
        $utc = new DateTimeZone('UTC');
        self::assertEvidenceEnvelopeShape([
            'contract' => $isLive ? self::EvidenceContract : self::TestEvidenceContract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => 'payload-shape-validator',
            'issued_at' => $finishedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $finishedAt->modify('+1 second')->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'evidence' => $payload['evidence'],
            'probe' => $payload['probe'],
            'run' => $payload['run'],
            'consumption' => $payload['consumption'],
            'authorizations' => $payload['authorizations'],
            'commitments' => $payload['commitments'],
            ...($isLive ? ['origins' => $payload['origins']] : []),
        ], $isLive ? self::EvidenceContract : self::TestEvidenceContract, $isLive);
    }

    /** @param array<string, mixed> $envelope */
    private static function assertEvidenceEnvelopeShape(
        array $envelope,
        string $expectedContract = self::EvidenceContract,
        bool $requireLiveOrigins = true,
    ): void {
        $expectedKeys = [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'evidence',
            'probe',
            'run',
            'consumption',
            'authorizations',
            'commitments',
        ];

        if ($requireLiveOrigins) {
            $expectedKeys[] = 'origins';
        }

        if (! self::hasExactKeys($envelope, $expectedKeys)
            || ($envelope['contract'] ?? null) !== $expectedContract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! is_array($envelope['evidence'] ?? null)
            || ! self::hasExactKeys($envelope['evidence'], ['contract', 'fixture_path', 'fixture_sha256'])
            || ! is_string($envelope['evidence']['contract'] ?? null)
            || ! is_string($envelope['evidence']['fixture_path'] ?? null)
            || ! self::isContractFixturePath($envelope['evidence']['fixture_path'])
            || ! is_string($envelope['evidence']['fixture_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['evidence']['fixture_sha256']) !== 1
            || ! is_array($envelope['probe'] ?? null)
            || ! self::hasExactKeys($envelope['probe'], ['repository_commit', 'code_sha256', 'archived_harness'])
            || ! is_string($envelope['probe']['repository_commit'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $envelope['probe']['repository_commit']) !== 1
            || ! is_string($envelope['probe']['code_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['probe']['code_sha256']) !== 1
            || ! is_array($envelope['probe']['archived_harness'] ?? null)
            || ! hash_equals(
                $envelope['probe']['code_sha256'],
                hash('sha256', self::canonicalJson($envelope['probe']['archived_harness'])),
            )
            || ! is_array($envelope['run'] ?? null)
            || ! self::hasExactKeys($envelope['run'], ['started_at', 'finished_at', 'environment', 'launch_manifest_sha256'])
            || ! is_string($envelope['run']['started_at'] ?? null)
            || ! is_string($envelope['run']['finished_at'] ?? null)
            || ! is_string($envelope['run']['environment'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $envelope['run']['environment']) !== 1
            || ! is_string($envelope['run']['launch_manifest_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $envelope['run']['launch_manifest_sha256']) !== 1
            || ! is_array($envelope['consumption'] ?? null)
            || ! is_array($envelope['authorizations'] ?? null)
            || ! array_is_list($envelope['authorizations'])
            || $envelope['authorizations'] === []
            || ! is_array($envelope['commitments'] ?? null)
            || ! self::hasExactKeys($envelope['commitments'], [
                'scheme',
                'target_set_sha256',
                'configuration_set_sha256',
                'policy_set_sha256',
                'safety_set_sha256',
                'templates_set_sha256',
                'limits_set_sha256',
                'fixture_policy_sha256',
            ])
            || ($envelope['commitments']['scheme'] ?? null) !== self::EvidenceCommitmentScheme) {
            throw new InvalidArgumentException('The post-run evidence envelope has an invalid exact contract.');
        }

        if ($requireLiveOrigins) {
            self::assertLiveRuntimeOriginsShape($envelope['origins'], $envelope);
        }

        self::harnessManifest($envelope['evidence']['contract']);
        self::assertArchivedHarnessSnapshotShape($envelope['probe']['archived_harness']);
        self::strictUtcDate($envelope['run']['started_at']);
        self::strictUtcDate($envelope['run']['finished_at']);
        $consumption = $envelope['consumption'];

        if (! self::hasExactKeys($consumption, ['local_claim', 'authority_receipt', 'effect_execution_receipts'])
            || ! is_array($consumption['local_claim'] ?? null)
            || ! is_array($consumption['authority_receipt'] ?? null)
            || ! is_array($consumption['effect_execution_receipts'] ?? null)
            || ! array_is_list($consumption['effect_execution_receipts'])
            || ! self::hasExactKeys($consumption['authority_receipt'], ['envelope', 'signature'])
            || ! is_array($consumption['authority_receipt']['envelope'] ?? null)
            || ! is_string($consumption['authority_receipt']['signature'] ?? null)) {
            throw new InvalidArgumentException('The post-run evidence requires exact local and external authority consumption receipts.');
        }

        self::assertConsumptionReceiptShape($consumption['local_claim']);
        self::assertConsumptionAuthorityEnvelopeShape($consumption['authority_receipt']['envelope']);

        if ($requireLiveOrigins) {
            throw new RuntimeException('The supervisor-signed brokered effect-execution verifier is not provisioned; canonical live evidence remains disabled.');
        }
        $seenProfiles = [];
        $seenChallenges = [];
        $seenDocuments = [];

        foreach ($envelope['authorizations'] as $reference) {
            if (! is_array($reference)
                || ! self::hasExactKeys($reference, ['profile', 'challenge', 'sha256'])
                || ! is_string($reference['profile'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $reference['profile']) !== 1
                || ! is_string($reference['challenge'] ?? null)
                || ! is_string($reference['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $reference['sha256']) !== 1
                || isset($seenProfiles[$reference['profile']])
                || isset($seenChallenges[$reference['challenge']])
                || isset($seenDocuments[$reference['sha256']])) {
                throw new InvalidArgumentException('The post-run evidence authorization references are invalid.');
            }

            self::assertCanonicalChallenge($reference['challenge']);
            $seenProfiles[$reference['profile']] = true;
            $seenChallenges[$reference['challenge']] = true;
            $seenDocuments[$reference['sha256']] = true;
        }

        foreach (array_diff_key($envelope['commitments'], ['scheme' => true]) as $commitment) {
            if (! is_string($commitment) || preg_match('/^[a-f0-9]{64}$/', $commitment) !== 1) {
                throw new InvalidArgumentException('The post-run evidence commitments are invalid.');
            }
        }
    }

    /** @param array<string, mixed> $envelope */
    private static function assertLiveRuntimeOriginsShape(mixed $origins, array $envelope): void
    {
        if (! is_array($origins)
            || ! self::hasExactKeys($origins, [
                'contract',
                'version',
                'scheme',
                'authority_receipt_sha256',
                'claim_request_sha256',
                'provider_run_sha256',
                'launch_manifest_sha256',
            ])
            || ($origins['contract'] ?? null) !== self::LiveRuntimeOriginsContract
            || ($origins['version'] ?? null) !== self::Version
            || ($origins['scheme'] ?? null) !== self::LiveRuntimeOriginsScheme
            || ! is_array($envelope['consumption']['authority_receipt'] ?? null)) {
            throw new InvalidArgumentException('The canonical live evidence runtime-origin commitment is invalid.');
        }

        foreach (['authority_receipt_sha256', 'claim_request_sha256', 'provider_run_sha256', 'launch_manifest_sha256'] as $key) {
            if (! is_string($origins[$key] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $origins[$key]) !== 1) {
                throw new InvalidArgumentException('The canonical live evidence runtime-origin hashes are invalid.');
            }
        }

        $receipt = ConsumptionReceipt::fromArray($envelope['consumption']['authority_receipt']);
        $expectedProviderRunSha256 = self::providerRunBindingSha256(
            $envelope['evidence']['contract'],
            $envelope['run']['started_at'],
            $envelope['run']['finished_at'],
            $envelope['run']['environment'],
            $envelope['run']['launch_manifest_sha256'],
            $envelope['evidence']['fixture_sha256'],
        );

        if (! hash_equals($origins['authority_receipt_sha256'], self::signedDocumentSha256($envelope['consumption']['authority_receipt']))
            || ! hash_equals($origins['claim_request_sha256'], $receipt->envelope->claimRequest->sha256())
            || ! hash_equals($origins['provider_run_sha256'], $expectedProviderRunSha256)
            || ! hash_equals($origins['launch_manifest_sha256'], $envelope['run']['launch_manifest_sha256'])
            || ! hash_equals($origins['launch_manifest_sha256'], $receipt->envelope->claimRequest->harness['launch_manifest_sha256'])) {
            throw new InvalidArgumentException('The canonical live evidence runtime origins do not bind the exact receipt and provider run.');
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function assertBaseEnvelope(
        array $envelope,
        string $expectedContract,
        int $maximumTtlSeconds,
    ): array {
        $requiredKeys = ['contract', 'version', 'algorithm', 'signer_id', 'issued_at', 'expires_at'];

        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $envelope)) {
                throw new InvalidArgumentException("The attestation envelope is missing {$requiredKey}.");
            }
        }

        if ($expectedContract === ''
            || ($envelope['contract'] ?? null) !== $expectedContract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! is_string($envelope['signer_id'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $envelope['signer_id']) !== 1
            || ! is_string($envelope['issued_at'] ?? null)
            || ! is_string($envelope['expires_at'] ?? null)
            || $maximumTtlSeconds < 1) {
            throw new InvalidArgumentException('The live evidence attestation envelope header is invalid.');
        }

        self::canonicalJson($envelope);

        $issuedAt = self::strictUtcDate($envelope['issued_at']);
        $expiresAt = self::strictUtcDate($envelope['expires_at']);
        $ttlMicroseconds = self::instantMicroseconds($expiresAt) - self::instantMicroseconds($issuedAt);

        if ($ttlMicroseconds < 1
            || $ttlMicroseconds > $maximumTtlSeconds * 1_000_000) {
            throw new InvalidArgumentException('The live evidence attestation has an invalid validity window.');
        }

        return [$issuedAt, $expiresAt];
    }

    private static function instantMicroseconds(DateTimeImmutable $value): int
    {
        return ((int) $value->format('U') * 1_000_000) + (int) $value->format('u');
    }

    private static function systemUtcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function monotonicNanoseconds(): int
    {
        $value = self::monotonicClockValue();

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('The trusted monotonic clock is unavailable.');
        }

        return $value;
    }

    private static function canonicalUtc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function strictUtcDate(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value) !== 1) {
            throw new InvalidArgumentException('Attestation timestamps must use UTC RFC3339 with six fractional digits.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException('The attestation timestamp is not a valid UTC instant.');
        }

        return $date;
    }

    /** @param array<array-key, mixed> $trustedSigners */
    private static function assertTrustedSignerMap(array $trustedSigners): void
    {
        if (array_is_list($trustedSigners) && $trustedSigners !== []) {
            throw new InvalidArgumentException('Trusted attestation signers must be keyed by signer ID.');
        }

        foreach ($trustedSigners as $signerId => $publicKey) {
            if (! is_string($signerId)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $signerId) !== 1
                || ! is_string($publicKey)) {
                throw new InvalidArgumentException('The trusted attestation signer map is invalid.');
            }

            $decoded = self::decodeCanonicalBase64($publicKey, 'operator public key');

            if (strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('A trusted operator public key has an invalid length.');
            }
        }
    }

    /**
     * @param  array<string, string>  $operatorSigners
     * @param  array<string, string>  $consumptionAuthorities
     */
    private static function assertProvisionedDisjointTrustRoles(
        array $operatorSigners,
        array $consumptionAuthorities,
    ): void {
        self::assertTrustedSignerMap($operatorSigners);
        self::assertTrustedSignerMap($consumptionAuthorities);

        if ($operatorSigners === []
            || $consumptionAuthorities === []
            || array_intersect_key($operatorSigners, $consumptionAuthorities) !== []) {
            throw new InvalidArgumentException('Production evidence requires non-empty, disjoint operator and consumption-authority signer IDs.');
        }

        $operatorKeyFingerprints = [];

        foreach ($operatorSigners as $publicKey) {
            $operatorKeyFingerprints[hash('sha256', self::decodeCanonicalBase64($publicKey, 'operator public key'))] = true;
        }

        foreach ($consumptionAuthorities as $publicKey) {
            $fingerprint = hash('sha256', self::decodeCanonicalBase64($publicKey, 'consumption authority public key'));

            if (isset($operatorKeyFingerprints[$fingerprint])) {
                throw new InvalidArgumentException('Operator and consumption-authority roles must not share Ed25519 key material.');
            }
        }
    }

    private static function assertSodiumAvailable(): void
    {
        if (! extension_loaded('sodium')
            || ! function_exists('sodium_crypto_sign_verify_detached')
            || ! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            || ! defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            throw new RuntimeException('The Sodium Ed25519 verifier is unavailable.');
        }
    }

    /**
     * @param  'pinned_remote'|'mock_test'  $origin
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    private static function brandVerifiedFreshClaimGrant(
        FreshClaimGrant $grant,
        string $origin,
        array $signedAuthorizations,
        ConsumptionClaimRequest $claimRequest,
        DateTimeImmutable $observedAt,
    ): VerifiedFreshClaimGrant {
        $issuer = \Closure::bind(
            static fn (FreshClaimGrant $verifiedGrant): VerifiedFreshClaimGrant => new VerifiedFreshClaimGrant($verifiedGrant),
            null,
            VerifiedFreshClaimGrant::class,
        );

        $verifiedGrant = $issuer($grant);
        self::verifiedFreshGrantRegistry()[$verifiedGrant] = [
            'origin' => $origin,
            'receipt_sha256' => self::signedDocumentSha256($verifiedGrant->toArray()),
            'request_sha256' => $claimRequest->sha256(),
            'authorization_batch_sha256' => self::authorizationBatchSha256($signedAuthorizations),
            'last_observed_microseconds' => self::instantMicroseconds($observedAt),
        ];

        return $verifiedGrant;
    }

    /**
     * @param  'pinned_remote'|'mock_test'  $expectedOrigin
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    private static function assertRegisteredVerifiedFreshGrant(
        VerifiedFreshClaimGrant $grant,
        string $expectedOrigin,
        array $signedAuthorizations,
        ConsumptionClaimRequest $claimRequest,
        DateTimeImmutable $observedAt,
    ): void {
        $registry = self::verifiedFreshGrantRegistry();
        $context = self::registeredFreshGrantContext($grant);
        $observedMicroseconds = self::instantMicroseconds($observedAt);

        if (! is_array($context)
            || ($context['origin'] ?? null) !== $expectedOrigin
            || ($context['receipt_sha256'] ?? null) !== self::signedDocumentSha256($grant->toArray())
            || ($context['request_sha256'] ?? null) !== $claimRequest->sha256()
            || ($context['authorization_batch_sha256'] ?? null) !== self::authorizationBatchSha256($signedAuthorizations)
            || ! is_int($context['last_observed_microseconds'] ?? null)
            || $observedMicroseconds < $context['last_observed_microseconds']) {
            throw new InvalidArgumentException('The verified fresh-claim token is forged, cross-used or observed out of order.');
        }

        $registry[$grant] = [
            'origin' => $expectedOrigin,
            'receipt_sha256' => self::signedDocumentSha256($grant->toArray()),
            'request_sha256' => $claimRequest->sha256(),
            'authorization_batch_sha256' => self::authorizationBatchSha256($signedAuthorizations),
            'last_observed_microseconds' => $observedMicroseconds,
        ];
    }

    /** @param list<array<string, mixed>> $signedAuthorizations */
    private static function authorizationBatchSha256(array $signedAuthorizations): string
    {
        return hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.authorization-consumption-batch',
            'version' => self::Version,
            'signed_authorizations' => $signedAuthorizations,
        ]));
    }

    /**
     * @return \WeakMap<VerifiedFreshClaimGrant, array{
     *     origin: 'pinned_remote'|'mock_test',
     *     receipt_sha256: string,
     *     request_sha256: string,
     *     authorization_batch_sha256: string,
     *     last_observed_microseconds: int
     * }>
     */
    private static function verifiedFreshGrantRegistry(): \WeakMap
    {
        return self::$verifiedFreshGrantRegistry ??= new \WeakMap;
    }

    /**
     * @return \WeakMap<LiveProviderRunHandle, array{
     *     evidence_contract: string,
     *     environment: string,
     *     launch_manifest_sha256: string,
     *     claim_request_sha256: string,
     *     started_at: string,
     *     started_monotonic_nanoseconds: int
     * }>
     */
    private static function liveProviderRunHandleRegistry(): \WeakMap
    {
        return self::$liveProviderRunHandleRegistry ??= new \WeakMap;
    }

    /**
     * @return \WeakMap<VerifiedLiveProviderRun, array{
     *     origin: 'real_provider',
     *     evidence_contract: string,
     *     environment: string,
     *     launch_manifest_sha256: string,
     *     claim_request_sha256: string,
     *     started_at: string,
     *     finished_at: string,
     *     finished_monotonic_nanoseconds: int
     * }>
     */
    private static function verifiedLiveProviderRunRegistry(): \WeakMap
    {
        return self::$verifiedLiveProviderRunRegistry ??= new \WeakMap;
    }

    /**
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    private static function assertPinnedRemoteGrantForLiveEvidence(
        VerifiedFreshClaimGrant $grant,
        array $signedAuthorizations,
    ): ConsumptionClaimRequest {
        $receipt = ConsumptionReceipt::fromArray($grant->toArray());
        $claimRequest = $receipt->envelope->claimRequest;
        $context = self::registeredFreshGrantContext($grant);

        if (! is_array($context)
            || ($context['origin'] ?? null) !== 'pinned_remote'
            || ($context['receipt_sha256'] ?? null) !== self::signedDocumentSha256($grant->toArray())
            || ($context['request_sha256'] ?? null) !== $claimRequest->sha256()
            || ($context['authorization_batch_sha256'] ?? null) !== self::authorizationBatchSha256($signedAuthorizations)) {
            throw new InvalidArgumentException('Canonical live evidence requires an exact pinned-remote authority grant.');
        }

        return $claimRequest;
    }

    /**
     * @return array{
     *     origin: 'real_provider',
     *     evidence_contract: string,
     *     environment: string,
     *     launch_manifest_sha256: string,
     *     claim_request_sha256: string,
     *     started_at: string,
     *     finished_at: string,
     *     finished_monotonic_nanoseconds: int
     * }
     */
    private static function assertVerifiedLiveProviderRun(
        VerifiedLiveProviderRun $run,
        string $evidenceContract,
    ): array {
        $context = self::registeredLiveProviderRunContext($run);

        if (! is_array($context)
            || ($context['origin'] ?? null) !== 'real_provider'
            || ($context['evidence_contract'] ?? null) !== $evidenceContract
            || ! is_string($context['environment'] ?? null)
            || ! is_string($context['launch_manifest_sha256'] ?? null)
            || ! is_string($context['claim_request_sha256'] ?? null)
            || ! is_string($context['started_at'] ?? null)
            || ! is_string($context['finished_at'] ?? null)
            || ! is_int($context['finished_monotonic_nanoseconds'] ?? null)) {
            throw new InvalidArgumentException('Canonical live evidence requires the exact real-provider run brand.');
        }

        return [
            'origin' => 'real_provider',
            'evidence_contract' => $evidenceContract,
            'environment' => $context['environment'],
            'launch_manifest_sha256' => $context['launch_manifest_sha256'],
            'claim_request_sha256' => $context['claim_request_sha256'],
            'started_at' => $context['started_at'],
            'finished_at' => $context['finished_at'],
            'finished_monotonic_nanoseconds' => $context['finished_monotonic_nanoseconds'],
        ];
    }

    /**
     * @param  array{origin: 'real_provider', evidence_contract: string, environment: string, launch_manifest_sha256: string, started_at: string, finished_at: string, finished_monotonic_nanoseconds: int}  $providerContext
     * @return array<string, string>
     */
    private static function liveRuntimeOrigins(
        VerifiedFreshClaimGrant $authorityGrant,
        ConsumptionClaimRequest $claimRequest,
        array $providerContext,
        string $fixtureSha256,
    ): array {
        return [
            'contract' => self::LiveRuntimeOriginsContract,
            'version' => self::Version,
            'scheme' => self::LiveRuntimeOriginsScheme,
            'authority_receipt_sha256' => self::signedDocumentSha256($authorityGrant->toArray()),
            'claim_request_sha256' => $claimRequest->sha256(),
            'launch_manifest_sha256' => $providerContext['launch_manifest_sha256'],
            'provider_run_sha256' => self::providerRunBindingSha256(
                $providerContext['evidence_contract'],
                $providerContext['started_at'],
                $providerContext['finished_at'],
                $providerContext['environment'],
                $providerContext['launch_manifest_sha256'],
                $fixtureSha256,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $signedAuthorizations
     */
    private static function assertLivePayloadMatchesRuntimeBrands(
        array $payload,
        array $signedAuthorizations,
        VerifiedFreshClaimGrant $authorityGrant,
        VerifiedLiveProviderRun $providerRun,
    ): void {
        self::assertEvidencePayloadShape($payload);

        if (($payload['contract'] ?? null) !== self::EvidencePayloadContract) {
            throw new InvalidArgumentException('Only a canonical dual-origin live payload may be published.');
        }

        $claimRequest = self::assertPinnedRemoteGrantForLiveEvidence($authorityGrant, $signedAuthorizations);
        $providerContext = self::assertVerifiedLiveProviderRun($providerRun, $payload['evidence']['contract']);
        $expectedOrigins = self::liveRuntimeOrigins(
            $authorityGrant,
            $claimRequest,
            $providerContext,
            $payload['evidence']['fixture_sha256'],
        );

        if (! hash_equals(self::canonicalJson($payload['origins']), self::canonicalJson($expectedOrigins))
            || ! hash_equals(
                self::canonicalJson($payload['consumption']['authority_receipt']),
                self::canonicalJson($authorityGrant->toArray()),
            )
            || $payload['run']['started_at'] !== $providerContext['started_at']
            || $payload['run']['finished_at'] !== $providerContext['finished_at']
            || $payload['run']['environment'] !== $providerContext['environment']
            || $payload['run']['launch_manifest_sha256'] !== $providerContext['launch_manifest_sha256']) {
            throw new InvalidArgumentException('The live payload does not bind both exact process-local runtime origins.');
        }
    }

    private static function providerRunBindingSha256(
        string $evidenceContract,
        string $startedAt,
        string $finishedAt,
        string $environment,
        string $launchManifestSha256,
        string $fixtureSha256,
    ): string {
        return hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.live-provider-run-binding',
            'version' => self::Version,
            'evidence_contract' => $evidenceContract,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'environment' => $environment,
            'launch_manifest_sha256' => $launchManifestSha256,
            'fixture_sha256' => $fixtureSha256,
        ]));
    }

    private static function decodeCanonicalBase64(string $value, string $label): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false || base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException("The {$label} is not canonical base64.");
        }

        return $decoded;
    }

    private static function assertCanonicalChallenge(string $challenge): void
    {
        $decoded = self::decodeCanonicalBase64($challenge, 'authorization challenge');

        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException('The authorization challenge must contain 32 random bytes.');
        }
    }

    private static function isContractFixturePath(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);

        return array_slice($segments, 0, 3) === ['tests', 'Fixtures', 'Contract']
            && count($segments) === 4
            && ! str_contains($relativePath, '*')
            && ! str_contains($relativePath, '?')
            && str_ends_with($relativePath, '.json');
    }

    private static function readRepositoryFile(string $repositoryRoot, string $relativePath): string
    {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1) {
            throw new RuntimeException('A harness manifest path is not canonical.');
        }

        $root = realpath($repositoryRoot);
        $segments = explode('/', $relativePath);

        if ($root === false || ! is_dir($root) || is_link($repositoryRoot)) {
            throw new RuntimeException('The harness repository root is invalid.');
        }

        $candidate = $root;

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('A harness manifest path is not canonical.');
            }

            $candidate .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($candidate)) {
                throw new RuntimeException('A harness manifest path contains a symlink.');
            }
        }

        $resolved = realpath($candidate);
        $pathStat = $resolved === false ? false : lstat($resolved);
        $handle = $resolved === false ? false : fopen($resolved, 'rb');

        if ($resolved === false
            || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            || $pathStat === false
            || $pathStat['nlink'] !== 1
            || $handle === false) {
            throw new RuntimeException('A harness manifest file is missing or unsafe.');
        }

        try {
            $openedStat = fstat($handle);
            $contents = stream_get_contents($handle);
            $finishedStat = fstat($handle);
            $currentPathStat = self::filesystemMetadata($resolved);
        } finally {
            fclose($handle);
        }

        if ($openedStat === false
            || $contents === false
            || $finishedStat === false
            || ! is_array($currentPathStat)
            || $openedStat['nlink'] !== 1
            || $finishedStat['nlink'] !== 1
            || $currentPathStat['nlink'] !== 1
            || $pathStat['dev'] !== $openedStat['dev']
            || $pathStat['ino'] !== $openedStat['ino']
            || $openedStat['dev'] !== $finishedStat['dev']
            || $openedStat['ino'] !== $finishedStat['ino']
            || $openedStat['size'] !== $finishedStat['size']
            || $openedStat['dev'] !== $currentPathStat['dev']
            || $openedStat['ino'] !== $currentPathStat['ino']
            || $openedStat['size'] !== strlen($contents)) {
            throw new RuntimeException('A harness manifest file changed while it was being read.');
        }

        return $contents;
    }

    private static function secureOperatorClaimDirectory(string $directory, string $repositoryRoot): string
    {
        if (! str_starts_with($directory, DIRECTORY_SEPARATOR)
            || str_contains($directory, "\0")
            || is_link($directory)) {
            throw new RuntimeException('The operator authorization claim directory is not canonical.');
        }

        $resolved = realpath($directory);
        $root = realpath($repositoryRoot);
        $metadata = $resolved === false ? false : lstat($resolved);
        $permissions = $resolved === false ? false : fileperms($resolved);
        $owner = $resolved === false ? false : fileowner($resolved);

        if ($resolved === false
            || $resolved !== $directory
            || $root === false
            || $resolved === $root
            || str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)
            || ! is_dir($resolved)
            || $metadata === false
            || ($metadata['mode'] & 0170000) !== 0040000
            || $permissions === false
            || ($permissions & 0777) !== 0700
            || ! function_exists('posix_geteuid')
            || $owner === false
            || $owner !== posix_geteuid()) {
            throw new RuntimeException('The operator authorization claim directory must be an owner-only 0700 directory outside the repository.');
        }

        return $resolved;
    }

    public static function operatorClaimStoreIdentitySha256(string $repositoryRoot): string
    {
        $directory = self::secureOperatorClaimDirectory(
            LiveEvidenceClaimStore::operatorDefault()->directory(),
            $repositoryRoot,
        );

        return self::claimStoreIdentitySha256($directory);
    }

    /** Explicit dependency-injected unit seam. */
    public static function claimStoreIdentitySha256ForTesting(
        LiveEvidenceClaimStore $claimStore,
        string $repositoryRoot,
    ): string {
        return self::claimStoreIdentitySha256(
            self::secureOperatorClaimDirectory($claimStore->directory(), $repositoryRoot),
        );
    }

    /** @return array{contract: string, version: string, store_nonce: string} */
    public static function claimStoreMetadataForTesting(string $storeNonce): array
    {
        $decoded = self::decodeCanonicalBase64($storeNonce, 'claim store nonce');

        if (strlen($decoded) !== 32) {
            throw new InvalidArgumentException('The claim store nonce must contain 32 random bytes.');
        }

        return [
            'contract' => 'cieplik206.fakturownia.authorization-claim-store',
            'version' => self::Version,
            'store_nonce' => $storeNonce,
        ];
    }

    private static function claimStoreIdentitySha256(string $directory): string
    {
        $metadataPath = $directory.'/.store.json';
        $contents = self::readOwnerOnlyClaimFile($metadataPath);

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The operator claim store metadata is corrupt.', previous: $exception);
        }

        if (! is_array($metadata)
            || ! self::hasExactKeys($metadata, ['contract', 'version', 'store_nonce'])
            || ($metadata['contract'] ?? null) !== 'cieplik206.fakturownia.authorization-claim-store'
            || ($metadata['version'] ?? null) !== self::Version
            || ! is_string($metadata['store_nonce'] ?? null)
            || $contents !== self::canonicalJson($metadata)) {
            throw new RuntimeException('The operator claim store metadata has an invalid exact contract.');
        }

        $nonce = self::decodeCanonicalBase64($metadata['store_nonce'], 'claim store nonce');
        $directoryMetadata = lstat($directory);

        if (strlen($nonce) !== 32 || $directoryMetadata === false) {
            throw new RuntimeException('The operator claim store identity cannot be derived safely.');
        }

        return hash('sha256', self::canonicalJson([
            'contract' => 'cieplik206.fakturownia.authorization-claim-store-identity',
            'version' => self::Version,
            'device' => (string) $directoryMetadata['dev'],
            'inode' => (string) $directoryMetadata['ino'],
            'canonical_path_hmac_sha256' => hash_hmac('sha256', $directory, $nonce),
            'metadata_sha256' => hash('sha256', $contents),
        ]));
    }

    private static function readOwnerOnlyClaimFile(string $path): string
    {
        $metadata = lstat($path);

        if ($metadata === false) {
            throw new RuntimeException('The owner-only authorization claim file is missing.');
        }

        self::assertOwnerOnlyClaimFileMetadata($path, $metadata);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The owner-only authorization claim file cannot be read.');
        }

        try {
            $opened = fstat($handle);
            $contents = stream_get_contents($handle);
            $finished = fstat($handle);
            $current = lstat($path);
        } finally {
            fclose($handle);
        }

        if ($opened === false
            || $contents === false
            || $finished === false
            || $current === false
            || $opened['dev'] !== $finished['dev']
            || $opened['ino'] !== $finished['ino']
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']
            || $opened['nlink'] !== 1
            || $finished['nlink'] !== 1
            || $current['nlink'] !== 1
            || $opened['size'] !== strlen($contents)) {
            throw new RuntimeException('The owner-only authorization claim file changed while it was read.');
        }

        return $contents;
    }

    /** @return resource */
    private static function openOwnerOnlyClaimFile(string $path)
    {
        clearstatcache(true, $path);
        $metadata = \file_exists($path) ? \lstat($path) : false;

        if ($metadata === false) {
            $previousMask = umask(0077);

            try {
                $handle = fopen($path, 'x+b');
            } finally {
                umask($previousMask);
            }
        } else {
            self::assertOwnerOnlyClaimFileMetadata($path, $metadata);
            $handle = fopen($path, 'r+b');
        }

        if ($handle === false) {
            throw new RuntimeException('The owner-only authorization claim file could not be opened safely.');
        }

        if (! chmod($path, 0600)) {
            fclose($handle);

            throw new RuntimeException('The authorization claim file permissions could not be restricted.');
        }

        $opened = fstat($handle);
        $current = lstat($path);

        if ($opened === false
            || $current === false
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']
            || $opened['nlink'] !== 1
            || $current['nlink'] !== 1
            || ($opened['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0777) !== 0600) {
            fclose($handle);

            throw new RuntimeException('The owner-only authorization claim file changed while it was opened.');
        }

        return $handle;
    }

    /** @param array<string|int, mixed> $metadata */
    private static function assertOwnerOnlyClaimFileMetadata(string $path, array $metadata): void
    {
        $permissions = fileperms($path);
        $owner = fileowner($path);

        if (is_link($path)
            || ! is_file($path)
            || ($metadata['mode'] & 0170000) !== 0100000
            || $metadata['nlink'] !== 1
            || $permissions === false
            || ($permissions & 0777) !== 0600
            || ! function_exists('posix_geteuid')
            || $owner === false
            || $owner !== posix_geteuid()) {
            throw new RuntimeException('The authorization claim store contains an unsafe file.');
        }
    }

    private static function writeExclusiveOwnerOnlyFile(string $path, string $contents): void
    {
        $previousMask = umask(0077);

        try {
            $handle = fopen($path, 'x+b');
        } finally {
            umask($previousMask);
        }

        if ($handle === false) {
            throw new RuntimeException('The authorization claim already exists or cannot be created exclusively.');
        }

        $written = 0;

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('The authorization claim permissions could not be restricted before writing.');
            }

            while ($written < strlen($contents)) {
                $bytes = fwrite($handle, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('The authorization claim could not be written completely.');
                }

                $written += $bytes;
            }

            if (! fflush($handle)
                || function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('The authorization claim could not be flushed durably.');
            }

            $metadata = fstat($handle);

            if ($metadata === false
                || $metadata['nlink'] !== 1
                || ($metadata['mode'] & 0777) !== 0600) {
                throw new RuntimeException('The authorization claim metadata is unsafe.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);

            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }

            throw $exception;
        }

        fclose($handle);
    }

    private static function durablySyncClaimStore(): void
    {
        $process = proc_open(
            [self::SyncBinary],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            self::sanitizedProcessEnvironment(),
        );

        if (! is_resource($process)
            || ! isset($pipes[0], $pipes[1], $pipes[2])
            || ! is_resource($pipes[0])
            || ! is_resource($pipes[1])
            || ! is_resource($pipes[2])) {
            throw new RuntimeException('The authorization claim directory entry cannot be durably synchronized.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('The authorization claim directory entry could not be durably synchronized.');
        }
    }

    /** @return array{challenge_hashes: array<string, true>, run_ids: array<string, true>} */
    private static function assertExistingClaimRecordsAreSafe(string $directory): array
    {
        $challengeHashes = [];
        $runIds = [];
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                throw new RuntimeException('The authorization claim directory contains an unreadable entry.');
            }

            $name = $file->getFilename();

            if ($name === '.claims.lock' || $name === '.store.json') {
                continue;
            }

            if (preg_match('/^claim-([a-f0-9]{64})-([a-f0-9]{64})\.json$/', $name, $matches) !== 1) {
                throw new RuntimeException('The authorization claim directory contains an unexpected entry.');
            }

            $path = $file->getPathname();
            $metadata = lstat($path);

            if ($metadata === false) {
                throw new RuntimeException('An authorization claim disappeared during validation.');
            }

            self::assertOwnerOnlyClaimFileMetadata($path, $metadata);
            $contents = file_get_contents($path);

            try {
                $record = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('An authorization claim record is corrupt.', previous: $exception);
            }

            if (! is_array($record)
                || ! self::hasExactKeys($record, ['contract', 'version', 'authorization_sha256', 'challenge_sha256', 'receipt'])
                || ($record['contract'] ?? null) !== 'cieplik206.fakturownia.authorization-claim-record'
                || ($record['version'] ?? null) !== self::Version
                || ($record['authorization_sha256'] ?? null) !== $matches[1]
                || ($record['challenge_sha256'] ?? null) !== $matches[2]
                || ! is_array($record['receipt'] ?? null)
                || $contents !== self::canonicalJson($record)) {
                throw new RuntimeException('An authorization claim record has an invalid exact contract.');
            }

            self::assertConsumptionReceiptShape($record['receipt']);
            $challengeHashes[$matches[2]] = true;
            $runIds[$record['receipt']['run_id']] = true;
        }

        return ['challenge_hashes' => $challengeHashes, 'run_ids' => $runIds];
    }

    /** @param array<string, mixed> $receipt */
    private static function assertConsumptionReceiptShape(array $receipt): void
    {
        if (! self::hasExactKeys($receipt, [
            'contract',
            'version',
            'store_identity_sha256',
            'run_id',
            'claimed_at',
            'authorization_set_sha256',
            'challenge_set_sha256',
            'harness',
            'configuration_set_sha256',
            'replay_policy',
        ])
            || ($receipt['contract'] ?? null) !== self::ConsumptionReceiptContract
            || ($receipt['version'] ?? null) !== self::Version
            || ! is_string($receipt['store_identity_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $receipt['store_identity_sha256']) !== 1
            || ! is_string($receipt['run_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $receipt['run_id']) !== 1
            || ! is_string($receipt['claimed_at'] ?? null)
            || ! is_array($receipt['harness'] ?? null)
            || ! self::hasExactKeys($receipt['harness'], ['repository_commit', 'code_sha256', 'launch_manifest_sha256'])
            || ($receipt['replay_policy'] ?? null) !== self::ConsumptionReplayPolicy) {
            throw new InvalidArgumentException('The authorization consumption receipt has an invalid exact contract.');
        }

        self::strictUtcDate($receipt['claimed_at']);

        foreach (['authorization_set_sha256', 'challenge_set_sha256', 'configuration_set_sha256'] as $key) {
            if (! is_string($receipt[$key] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $receipt[$key]) !== 1) {
                throw new InvalidArgumentException('The authorization consumption receipt hashes are invalid.');
            }
        }

        if (! is_string($receipt['harness']['repository_commit'] ?? null)
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $receipt['harness']['repository_commit']) !== 1
            || ! is_string($receipt['harness']['code_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $receipt['harness']['code_sha256']) !== 1
            || ! is_string($receipt['harness']['launch_manifest_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $receipt['harness']['launch_manifest_sha256']) !== 1) {
            throw new InvalidArgumentException('The authorization consumption receipt harness is invalid.');
        }
    }

    /** @param list<string> $arguments */
    private static function runGit(string $repositoryRoot, array $arguments, bool $trimOutput = true): string
    {
        $pipes = [];
        $process = proc_open(
            [self::GitBinary, '-C', $repositoryRoot, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            self::sanitizedProcessEnvironment(),
        );

        if (! is_resource($process)
            || ! isset($pipes[0], $pipes[1], $pipes[2])
            || ! is_resource($pipes[0])
            || ! is_resource($pipes[1])
            || ! is_resource($pipes[2])) {
            throw new RuntimeException('Git could not be started for harness provenance validation.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $stdout === false || $stderr === false) {
            throw new RuntimeException('Git rejected the signed harness repository provenance.');
        }

        return $trimOutput ? rtrim($stdout, "\r\n") : $stdout;
    }

    /** @return array<string, string> */
    private static function sanitizedProcessEnvironment(): array
    {
        return [
            'PATH' => '/usr/bin:/bin',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];
    }

    /** Composer's generated metadata is treated as untrusted until structurally validated. */
    private static function installedVersionSets(): mixed
    {
        return InstalledVersions::getAllRawData();
    }

    /** The loaded INI path is revalidated before it enters the attested runtime snapshot. */
    private static function loadedIniFile(): mixed
    {
        return php_ini_loaded_file();
    }

    /** The monotonic clock result is validated before it may brand a provider run. */
    private static function monotonicClockValue(): mixed
    {
        return hrtime(true);
    }

    /** Filesystem metadata is untrusted because the path may race after resolution. */
    private static function filesystemMetadata(string $path): mixed
    {
        return lstat($path);
    }

    /** Registry contents are revalidated at every branded-grant use boundary. */
    private static function registeredFreshGrantContext(VerifiedFreshClaimGrant $grant): mixed
    {
        return self::verifiedFreshGrantRegistry()[$grant] ?? null;
    }

    /** Registry contents are revalidated at every branded-provider-run use boundary. */
    private static function registeredLiveProviderRunContext(VerifiedLiveProviderRun $run): mixed
    {
        return self::verifiedLiveProviderRunRegistry()[$run] ?? null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expectedKeys);

        return $keys === $expectedKeys;
    }
}

/** Branded in-process seam for tests. Production runners require the native one-shot broker. */
interface LiveEvidenceConsumptionAuthority extends ConsumptionAuthority {}

final class LiveEvidenceClaimStore
{
    private function __construct(private string $directory) {}

    public static function operatorDefault(): self
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            throw new RuntimeException('The canonical operator claim store requires POSIX account metadata.');
        }

        $account = self::operatorAccount(posix_geteuid());
        $home = is_array($account) ? ($account['dir'] ?? null) : null;

        if (! is_string($home) || ! str_starts_with($home, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The canonical operator home directory cannot be resolved.');
        }

        return new self($home.'/.local/state/cieplik206/fakturownia/live-evidence-claims-v1');
    }

    /** POSIX account records are validated before deriving an operator-owned path. */
    private static function operatorAccount(int $userId): mixed
    {
        return posix_getpwuid($userId);
    }

    /** Explicit dependency-injected unit seam. */
    public static function forTesting(string $directory): self
    {
        return new self($directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }
}
