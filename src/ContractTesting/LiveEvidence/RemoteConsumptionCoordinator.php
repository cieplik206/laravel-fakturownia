<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final class RemoteConsumptionCoordinator
{
    private const AuthorizationSetContract = 'cieplik206.fakturownia.authorization-consumption-set';

    private const RawAuthorizationSetContract = 'cieplik206.fakturownia.remote-consumption-raw-authorization-set';

    private function __construct() {}

    /** @param array<array-key, mixed> $rawSignedAuthorizations */
    public static function claimNow(
        #[SensitiveParameter] string $verifiedRepositoryRoot,
        #[SensitiveParameter] VerifiedLaunchManifest $launchManifest,
        #[SensitiveParameter] array $rawSignedAuthorizations,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
        int $maximumRunSeconds,
    ): never {
        throw new BrokeredExecutionRequiredException(
            'Live consumption requires broker-owned authorization CAS and provider execution.',
        );
    }

    /**
     * Begins a one-shot literal-response test claim. It never enters Saloon
     * and can issue only an in-memory-test origin.
     *
     * @param  array<array-key, mixed>  $rawSignedAuthorizations
     */
    public static function beginLiteralInMemoryClaimNow(
        #[SensitiveParameter] string $verifiedRepositoryRoot,
        #[SensitiveParameter] array $rawSignedAuthorizations,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
        int $maximumRunSeconds,
    ): PendingLiteralRemoteConsumptionClaim {
        self::assertPolicyLimits(
            $maximumAuthorizationTtlSeconds,
            $minimumAuthorizationRemainingSeconds,
            $maximumReceiptTtlSeconds,
            $maximumRunSeconds,
        );

        [$runStartedAt, $runStartedMonotonicNanoseconds] = self::captureRunStart();
        $prepared = self::prepare(
            $verifiedRepositoryRoot,
            $rawSignedAuthorizations,
            $maximumAuthorizationTtlSeconds,
            $minimumAuthorizationRemainingSeconds,
            $runStartedAt,
            null,
        );
        $begin = Closure::bind(
            static fn (): PendingLiteralRemoteConsumptionClaim => PendingLiteralRemoteConsumptionClaim::begin([
                'prepared' => $prepared,
                'maximum_authorization_ttl_seconds' => $maximumAuthorizationTtlSeconds,
                'minimum_authorization_remaining_seconds' => $minimumAuthorizationRemainingSeconds,
                'maximum_receipt_ttl_seconds' => $maximumReceiptTtlSeconds,
                'maximum_run_seconds' => $maximumRunSeconds,
                'run_started_monotonic_nanoseconds' => $runStartedMonotonicNanoseconds,
                'consumed' => false,
            ]),
            null,
            PendingLiteralRemoteConsumptionClaim::class,
        );

        return $begin();
    }

    /**
     * @param  array<array-key, mixed>  $rawSignedAuthorizations
     * @return array{
     *     authorizations: non-empty-list<SignedLiveProbeAuthorization>,
     *     normalized: non-empty-list<array<string, mixed>>,
     *     request: ConsumptionClaimRequest,
     *     batch: LiveProbeAuthorizationBatch,
     *     policy: RemoteConsumptionAuthorityPolicy,
     *     repository_root: string,
     *     launch_manifest_sha256: string,
     *     raw_authorization_set_sha256: string
     * }
     */
    private static function prepare(
        #[SensitiveParameter] string $verifiedRepositoryRoot,
        #[SensitiveParameter] array $rawSignedAuthorizations,
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        DateTimeImmutable $runStartedAt,
        #[SensitiveParameter] ?string $requiredLaunchManifestSha256,
    ): array {
        $authorizations = self::parseAuthorizations($rawSignedAuthorizations);
        $first = $authorizations[0];
        $launchManifestSha256 = $first->harness['launch_manifest_sha256'];

        if ($requiredLaunchManifestSha256 !== null
            && ! \hash_equals($requiredLaunchManifestSha256, $launchManifestSha256)) {
            throw new InvalidArgumentException('The authorization does not bind the supervised launch manifest.');
        }

        $trustStore = PinnedLiveProbeTrustStore::load($verifiedRepositoryRoot);
        $policy = RemoteConsumptionAuthorityPolicyStore::load(
            $verifiedRepositoryRoot,
            $first->consumption['authority_id'],
        );
        $trustStore->assertAuthorityMatches($policy);

        if (! \hash_equals($first->consumption['authority_policy_sha256'], $policy->sha256())
            || $first->consumption['store_id'] !== $policy->storeId
            || ! \hash_equals($first->consumption['store_identity_sha256'], $policy->storeIdentitySha256)) {
            throw new InvalidArgumentException('The authorization does not bind the exact pinned remote authority policy.');
        }

        $request = self::buildClaimRequest(
            $authorizations,
            $runStartedAt,
            \base64_encode(\random_bytes(32)),
        );
        $batch = LiveProbeAuthorizationBatchAggregator::verifyForClaimRequestNow(
            $authorizations,
            $request,
            $trustStore->operatorKeyring(),
            $maximumAuthorizationTtlSeconds,
        );
        self::assertAuthorizationRemainingNow($authorizations, $minimumAuthorizationRemainingSeconds);
        $normalized = \array_map(
            static fn (SignedLiveProbeAuthorization $authorization): array => $authorization->toArray(),
            $authorizations,
        );
        $root = \realpath($verifiedRepositoryRoot);

        if (! \is_string($root)) {
            throw new RuntimeException('The verified live-evidence repository root is invalid.');
        }

        return [
            'authorizations' => $authorizations,
            'normalized' => $normalized,
            'request' => $request,
            'batch' => $batch,
            'policy' => $policy,
            'repository_root' => $root,
            'launch_manifest_sha256' => $launchManifestSha256,
            'raw_authorization_set_sha256' => self::rawAuthorizationSetSha256($normalized),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rawSignedAuthorizations
     * @return non-empty-list<SignedLiveProbeAuthorization>
     */
    private static function parseAuthorizations(
        #[SensitiveParameter] array $rawSignedAuthorizations,
    ): array {
        if (! \array_is_list($rawSignedAuthorizations)
            || $rawSignedAuthorizations === []
            || \count($rawSignedAuthorizations) > 16) {
            throw new InvalidArgumentException('The remote consumption coordinator requires one exact authorization list.');
        }

        $authorizations = [];

        foreach ($rawSignedAuthorizations as $document) {
            if (! \is_array($document) || \array_is_list($document)) {
                throw new InvalidArgumentException('The remote consumption coordinator received an invalid signed authorization.');
            }

            $authorizations[] = SignedLiveProbeAuthorization::fromArray($document);
        }

        /** @var non-empty-list<SignedLiveProbeAuthorization> $authorizations */
        return $authorizations;
    }

    /**
     * @param  non-empty-list<SignedLiveProbeAuthorization>  $authorizations
     */
    private static function buildClaimRequest(
        #[SensitiveParameter] array $authorizations,
        DateTimeImmutable $runStartedAt,
        #[SensitiveParameter] string $claimNonce,
    ): ConsumptionClaimRequest {
        $rows = \array_map(static fn (SignedLiveProbeAuthorization $authorization): array => [
            'profile' => $authorization->target['profile'],
            'authorization_sha256' => $authorization->sha256(),
            'challenge_sha256' => $authorization->challengeSha256(),
            'configuration_hmac_sha256' => $authorization->commitments['configuration_hmac_sha256'],
        ], $authorizations);
        \usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);
        $first = $authorizations[0];

        return new ConsumptionClaimRequest(
            $first->consumption['authority_id'],
            $first->consumption['authority_policy_sha256'],
            $first->consumption['store_id'],
            $first->consumption['store_identity_sha256'],
            $first->consumption['run_id'],
            $runStartedAt->format('Y-m-d\TH:i:s.u\Z'),
            $claimNonce,
            $first->harness,
            self::setSha256(\array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['authorization_sha256'],
            ], $rows)),
            self::setSha256(\array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['challenge_sha256'],
            ], $rows)),
            self::setSha256(\array_map(static fn (array $row): array => [
                'profile' => $row['profile'],
                'sha256' => $row['configuration_hmac_sha256'],
            ], $rows)),
        );
    }

    /** @param list<array{profile: string, sha256: string}> $value */
    private static function setSha256(#[SensitiveParameter] array $value): string
    {
        return \hash('sha256', CanonicalCodec::encode([
            'contract' => self::AuthorizationSetContract,
            'version' => SignedLiveProbeAuthorization::Version,
            'value' => $value,
        ]));
    }

    /** @param non-empty-list<array<string, mixed>> $normalized */
    private static function rawAuthorizationSetSha256(#[SensitiveParameter] array $normalized): string
    {
        return \hash('sha256', CanonicalCodec::encode([
            'contract' => self::RawAuthorizationSetContract,
            'version' => SignedLiveProbeAuthorization::Version,
            'documents' => $normalized,
        ]));
    }

    /** @param non-empty-list<SignedLiveProbeAuthorization> $authorizations */
    private static function assertAuthorizationRemainingNow(
        #[SensitiveParameter] array $authorizations,
        int $minimumRemainingSeconds,
    ): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $minimumExpiry = self::instantMicroseconds($now) + ($minimumRemainingSeconds * 1_000_000);

        foreach ($authorizations as $authorization) {
            if (self::instantMicroseconds($authorization->expiresAtInstant()) < $minimumExpiry) {
                throw new InvalidArgumentException('A signed authorization has insufficient remaining time for the atomic claim.');
            }
        }
    }

    /** @return array{0: DateTimeImmutable, 1: int} */
    private static function captureRunStart(): array
    {
        $monotonicNanoseconds = self::monotonicNanoseconds();
        $runStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [$runStartedAt, $monotonicNanoseconds];
    }

    private static function assertPolicyLimits(
        int $maximumAuthorizationTtlSeconds,
        int $minimumAuthorizationRemainingSeconds,
        int $maximumReceiptTtlSeconds,
        int $maximumRunSeconds,
    ): void {
        if ($maximumAuthorizationTtlSeconds < 1
            || $maximumAuthorizationTtlSeconds > 2_592_000
            || $minimumAuthorizationRemainingSeconds < 0
            || $minimumAuthorizationRemainingSeconds > $maximumAuthorizationTtlSeconds
            || $maximumReceiptTtlSeconds < 1
            || $maximumReceiptTtlSeconds > 86_400
            || $maximumRunSeconds < 1
            || $maximumRunSeconds > 21_600) {
            throw new InvalidArgumentException('The remote consumption coordinator policy limits are invalid.');
        }
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }

    private static function monotonicNanoseconds(): int
    {
        $nanoseconds = \hrtime(true);

        if (! \is_int($nanoseconds)) {
            throw new RuntimeException('A monotonic process clock is required for remote consumption claims.');
        }

        return $nanoseconds;
    }
}
