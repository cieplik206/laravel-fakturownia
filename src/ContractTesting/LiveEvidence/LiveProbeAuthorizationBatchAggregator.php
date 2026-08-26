<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

final class LiveProbeAuthorizationBatchAggregator
{
    private const SetContract = 'cieplik206.fakturownia.authorization-consumption-set';

    private const MaximumBatchBytes = 1_048_576;

    /**
     * @param  array<array-key, mixed>  $authorizations
     */
    public static function verifyForClaimRequestNow(
        #[SensitiveParameter] array $authorizations,
        #[SensitiveParameter] ConsumptionClaimRequest $claimRequest,
        #[SensitiveParameter] TrustedLiveProbeOperatorKeys $trustedOperators,
        int $maximumTtlSeconds,
    ): LiveProbeAuthorizationBatch {
        if (! \array_is_list($authorizations)
            || $authorizations === []
            || \count($authorizations) > 16) {
            throw new InvalidArgumentException('A live-probe authorization batch requires between one and sixteen signed documents.');
        }

        $canonicalBytes = 0;
        $rows = [];
        $authorityId = null;
        $authorityPolicySha256 = null;
        $storeId = null;
        $storeIdentitySha256 = null;
        $runId = null;
        $evidenceContract = null;
        $harness = null;
        $runStartedAt = self::strictUtcMicrosecondInstant($claimRequest->runStartedAt);

        foreach ($authorizations as $authorization) {
            if (! $authorization instanceof SignedLiveProbeAuthorization) {
                throw new InvalidArgumentException('The live-probe authorization batch contains a non-contract document.');
            }

            $canonicalBytes += \strlen($authorization->canonical());

            if ($canonicalBytes > self::MaximumBatchBytes) {
                throw new InvalidArgumentException('The live-probe authorization batch exceeds the canonical size limit.');
            }

            LiveProbeAuthorizationVerifier::verifyNow($authorization, $trustedOperators, $maximumTtlSeconds);

            if (self::instantMicroseconds($authorization->issuedAtInstant()) > self::instantMicroseconds($runStartedAt)
                || self::instantMicroseconds($authorization->expiresAtInstant()) <= self::instantMicroseconds($runStartedAt)) {
                throw new InvalidArgumentException('The claim run start is outside a signed authorization window.');
            }

            $consumption = $authorization->consumption;

            if ($authorityId !== null && $authorityId !== $consumption['authority_id']
                || $authorityPolicySha256 !== null && $authorityPolicySha256 !== $consumption['authority_policy_sha256']
                || $storeId !== null && $storeId !== $consumption['store_id']
                || $storeIdentitySha256 !== null && $storeIdentitySha256 !== $consumption['store_identity_sha256']
                || $runId !== null && $runId !== $consumption['run_id']
                || $evidenceContract !== null && $evidenceContract !== $authorization->evidenceContract
                || $harness !== null && $harness !== $authorization->harness) {
                throw new InvalidArgumentException('Every signed authorization must bind one authority, store, run, evidence contract and harness.');
            }

            $authorityId = $consumption['authority_id'];
            $authorityPolicySha256 = $consumption['authority_policy_sha256'];
            $storeId = $consumption['store_id'];
            $storeIdentitySha256 = $consumption['store_identity_sha256'];
            $runId = $consumption['run_id'];
            $evidenceContract = $authorization->evidenceContract;
            $harness = $authorization->harness;
            $rows[] = [
                'profile' => $authorization->target['profile'],
                'authorization_sha256' => $authorization->sha256(),
                'challenge_sha256' => $authorization->challengeSha256(),
                'configuration_hmac_sha256' => $authorization->commitments['configuration_hmac_sha256'],
            ];
        }

        \usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);
        self::assertUniqueRows($rows);

        $authorizationSetSha256 = self::setSha256(\array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['authorization_sha256'],
        ], $rows));
        $challengeSetSha256 = self::setSha256(\array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['challenge_sha256'],
        ], $rows));
        $configurationSetSha256 = self::setSha256(\array_map(static fn (array $row): array => [
            'profile' => $row['profile'],
            'sha256' => $row['configuration_hmac_sha256'],
        ], $rows));

        if ($claimRequest->authorityId !== $authorityId
            || ! \hash_equals($claimRequest->authorityPolicySha256, $authorityPolicySha256)
            || $claimRequest->storeId !== $storeId
            || ! \hash_equals($claimRequest->storeIdentitySha256, $storeIdentitySha256)
            || $claimRequest->runId !== $runId
            || $claimRequest->runStartedAt !== self::canonicalUtc($runStartedAt)
            || $claimRequest->harness !== $harness
            || ! \hash_equals($claimRequest->authorizationSetSha256, $authorizationSetSha256)
            || ! \hash_equals($claimRequest->challengeSetSha256, $challengeSetSha256)
            || ! \hash_equals($claimRequest->configurationSetSha256, $configurationSetSha256)) {
            throw new InvalidArgumentException('The claim request does not bind every canonical authorization batch field.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (self::instantMicroseconds($runStartedAt) > self::instantMicroseconds($now)) {
            throw new InvalidArgumentException('The claim request run start cannot be in the future.');
        }

        /** @var non-empty-list<array{profile: string, authorization_sha256: string, challenge_sha256: string, configuration_hmac_sha256: string}> $rows */
        return new LiveProbeAuthorizationBatch(
            $authorityId,
            $authorityPolicySha256,
            $storeId,
            $storeIdentitySha256,
            $runId,
            $claimRequest->runStartedAt,
            $claimRequest->claimNonce,
            $evidenceContract,
            ConsumptionClaimRequest::ReplayPolicy,
            $harness,
            $authorizationSetSha256,
            $challengeSetSha256,
            $configurationSetSha256,
            $rows,
        );
    }

    /**
     * @param  non-empty-list<array{profile: string, authorization_sha256: string, challenge_sha256: string, configuration_hmac_sha256: string}>  $rows
     */
    private static function assertUniqueRows(#[SensitiveParameter] array $rows): void
    {
        $profiles = \array_column($rows, 'profile');
        $authorizationHashes = \array_column($rows, 'authorization_sha256');
        $challengeHashes = \array_column($rows, 'challenge_sha256');

        if (\count($profiles) !== \count(\array_unique($profiles))
            || \count($authorizationHashes) !== \count(\array_unique($authorizationHashes))
            || \count($challengeHashes) !== \count(\array_unique($challengeHashes))) {
            throw new InvalidArgumentException('The live-probe authorization batch contains a duplicate profile, document or challenge.');
        }
    }

    /** @param list<array{profile: string, sha256: string}> $value */
    private static function setSha256(#[SensitiveParameter] array $value): string
    {
        return \hash('sha256', CanonicalCodec::encode([
            'contract' => self::SetContract,
            'version' => SignedLiveProbeAuthorization::Version,
            'value' => $value,
        ]));
    }

    private static function strictUtcMicrosecondInstant(string $value): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($instant === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $instant->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException('The claim request run start is not a strict UTC microsecond instant.');
        }

        return $instant;
    }

    private static function canonicalUtc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }
}
