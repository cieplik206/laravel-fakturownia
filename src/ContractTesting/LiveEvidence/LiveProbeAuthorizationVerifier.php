<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final class LiveProbeAuthorizationVerifier
{
    public static function verifyNow(
        #[SensitiveParameter] SignedLiveProbeAuthorization $authorization,
        #[SensitiveParameter] TrustedLiveProbeOperatorKeys $trustedOperators,
        int $maximumTtlSeconds,
    ): SignedLiveProbeAuthorization {
        self::assertSignatureAndTtl($authorization, $trustedOperators, $maximumTtlSeconds);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (self::instantMicroseconds($authorization->issuedAtInstant()) > self::instantMicroseconds($now)
            || self::instantMicroseconds($authorization->expiresAtInstant()) <= self::instantMicroseconds($now)) {
            throw new InvalidArgumentException('The signed live-probe authorization is not valid now.');
        }

        return $authorization;
    }

    private static function assertSignatureAndTtl(
        #[SensitiveParameter] SignedLiveProbeAuthorization $authorization,
        #[SensitiveParameter] TrustedLiveProbeOperatorKeys $trustedOperators,
        int $maximumTtlSeconds,
    ): void {
        self::assertSodiumAvailable();

        if ($maximumTtlSeconds < 1 || $maximumTtlSeconds > 2_592_000) {
            throw new InvalidArgumentException('The live-probe authorization maximum TTL is outside the fail-closed range.');
        }

        $issuedAt = $authorization->issuedAtInstant();
        $expiresAt = $authorization->expiresAtInstant();
        $ttlMicroseconds = self::instantMicroseconds($expiresAt) - self::instantMicroseconds($issuedAt);

        if ($ttlMicroseconds < 1 || $ttlMicroseconds > $maximumTtlSeconds * 1_000_000) {
            throw new InvalidArgumentException('The signed live-probe authorization has an invalid validity window.');
        }

        $signature = \base64_decode($authorization->signature, true);

        if ($signature === false
            || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES
            || ! \sodium_crypto_sign_verify_detached(
                $signature,
                $authorization->canonicalEnvelope(),
                $trustedOperators->publicKey($authorization->signerId),
            )) {
            throw new InvalidArgumentException('The signed live-probe authorization signature is invalid.');
        }
    }

    private static function instantMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }

    private static function assertSodiumAvailable(): void
    {
        if (! \extension_loaded('sodium')
            || ! \function_exists('sodium_crypto_sign_verify_detached')
            || ! \defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            || ! \defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            throw new RuntimeException('Ed25519 verification requires the sodium extension.');
        }
    }
}
