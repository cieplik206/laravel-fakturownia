<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final class RemoteConsumptionReceiptVerifier
{
    private function __construct() {}

    public static function verifyFreshNow(
        #[SensitiveParameter] ConsumptionReceipt $receipt,
        #[SensitiveParameter] ConsumptionClaimRequest $expectedRequest,
        #[SensitiveParameter] RemoteConsumptionAuthorityPolicy $policy,
        int $maximumTtlSeconds,
    ): void {
        self::verifyDispositionNow(
            $receipt,
            $expectedRequest,
            $policy,
            $maximumTtlSeconds,
            ConsumptionDisposition::FreshDirectGrant,
        );
    }

    public static function verifyRecoveredNow(
        #[SensitiveParameter] ConsumptionReceipt $receipt,
        #[SensitiveParameter] ConsumptionClaimRequest $expectedRequest,
        #[SensitiveParameter] RemoteConsumptionAuthorityPolicy $policy,
        int $maximumTtlSeconds,
    ): void {
        self::verifyDispositionNow(
            $receipt,
            $expectedRequest,
            $policy,
            $maximumTtlSeconds,
            ConsumptionDisposition::RecoveredConsumedProof,
        );
    }

    public static function verifyLiteralResultNow(
        int $status,
        #[SensitiveParameter] ConsumptionReceipt $receipt,
        #[SensitiveParameter] ConsumptionClaimRequest $expectedRequest,
        #[SensitiveParameter] RemoteConsumptionAuthorityPolicy $policy,
        int $maximumTtlSeconds,
    ): void {
        if ($status === 200
            && $receipt->envelope->disposition === ConsumptionDisposition::RecoveredConsumedProof) {
            self::verifyRecoveredNow($receipt, $expectedRequest, $policy, $maximumTtlSeconds);

            throw new RuntimeException('A recovered consumption proof can never authorize a mutating effect.');
        }

        if ($status !== 201
            || $receipt->envelope->disposition !== ConsumptionDisposition::FreshDirectGrant) {
            throw new RuntimeException('The remote authority status and signed fresh-direct disposition disagree.');
        }

        self::verifyFreshNow($receipt, $expectedRequest, $policy, $maximumTtlSeconds);
    }

    private static function verifyDispositionNow(
        #[SensitiveParameter] ConsumptionReceipt $receipt,
        #[SensitiveParameter] ConsumptionClaimRequest $expectedRequest,
        #[SensitiveParameter] RemoteConsumptionAuthorityPolicy $policy,
        int $maximumTtlSeconds,
        ConsumptionDisposition $expectedDisposition,
    ): void {
        self::assertSodiumAvailable();

        if ($maximumTtlSeconds < 1 || $maximumTtlSeconds > 86_400) {
            throw new InvalidArgumentException('The remote consumption receipt maximum TTL is outside the fail-closed range.');
        }

        $envelope = $receipt->envelope;

        if ($expectedRequest->authorityId !== $policy->authorityId
            || ! \hash_equals($expectedRequest->authorityPolicySha256, $policy->sha256())
            || $expectedRequest->storeId !== $policy->storeId
            || ! \hash_equals($expectedRequest->storeIdentitySha256, $policy->storeIdentitySha256)
            || ! \hash_equals($expectedRequest->canonical(), $envelope->claimRequest->canonical())
            || $envelope->signerId !== $policy->authorityId
            || $envelope->claimCursor->storeId !== $policy->storeId) {
            throw new InvalidArgumentException('The remote consumption receipt does not bind the exact authority, policy, store and request.');
        }

        $signature = \base64_decode($receipt->signature, true);

        if ($signature === false
            || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES
            || ! \sodium_crypto_sign_verify_detached(
                $signature,
                $envelope->canonical(),
                $policy->decodedAuthorityPublicKey(),
            )) {
            throw new InvalidArgumentException('The remote consumption receipt signature is invalid.');
        }

        $issuedAt = self::strictUtcMicrosecondInstant($envelope->issuedAt);
        $expiresAt = self::strictUtcMicrosecondInstant($envelope->expiresAt);
        $runStartedAt = self::strictUtcMicrosecondInstant($expectedRequest->runStartedAt);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ttlMicroseconds = self::instantMicroseconds($expiresAt) - self::instantMicroseconds($issuedAt);

        if ($ttlMicroseconds < 1
            || $ttlMicroseconds > $maximumTtlSeconds * 1_000_000
            || self::instantMicroseconds($issuedAt) < self::instantMicroseconds($runStartedAt)
            || self::instantMicroseconds($issuedAt) > self::instantMicroseconds($now)
            || self::instantMicroseconds($expiresAt) <= self::instantMicroseconds($now)
            || $envelope->disposition !== $expectedDisposition) {
            throw new InvalidArgumentException('The remote consumption receipt is not current for this exact run and disposition.');
        }
    }

    private static function strictUtcMicrosecondInstant(#[SensitiveParameter] string $value): DateTimeImmutable
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
            throw new InvalidArgumentException('The remote consumption receipt contains a non-canonical UTC instant.');
        }

        return $instant;
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }

    private static function assertSodiumAvailable(): void
    {
        if (! \extension_loaded('sodium')
            || ! \function_exists('sodium_crypto_sign_verify_detached')
            || ! \defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            throw new RuntimeException('Ed25519 verification requires the sodium extension.');
        }
    }
}
