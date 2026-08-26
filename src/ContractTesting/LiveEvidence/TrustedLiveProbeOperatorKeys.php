<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

final readonly class TrustedLiveProbeOperatorKeys
{
    /**
     * @param  non-empty-array<non-falsy-string, non-empty-string>  $encodedKeys
     * @param  non-empty-array<non-falsy-string, non-empty-string>  $decodedKeys
     * @param  non-empty-array<non-falsy-string, non-empty-string>  $fingerprints
     */
    private function __construct(
        private array $encodedKeys,
        private array $decodedKeys,
        private array $fingerprints,
    ) {}

    /** @param array<array-key, mixed> $encodedKeys */
    public static function fromBase64Map(#[SensitiveParameter] array $encodedKeys): self
    {
        self::assertSodiumAvailable();

        if ($encodedKeys === [] || \count($encodedKeys) > 64) {
            throw new InvalidArgumentException('The trusted live-probe operator keyring requires between one and sixty-four keys.');
        }

        $canonicalEncoded = [];
        $decoded = [];
        $fingerprints = [];
        $seenMaterial = [];

        foreach ($encodedKeys as $signerId => $encodedKey) {
            if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $signerId) !== 1
                || ! \is_string($encodedKey)) {
                throw new InvalidArgumentException('The trusted live-probe operator keyring contains an invalid signer.');
            }

            $publicKey = \base64_decode($encodedKey, true);

            if ($publicKey === false
                || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || \base64_encode($publicKey) !== $encodedKey) {
                throw new InvalidArgumentException('The trusted live-probe operator keyring contains an invalid Ed25519 public key.');
            }

            $fingerprint = \hash('sha256', $publicKey);

            if (isset($seenMaterial[$fingerprint])) {
                throw new InvalidArgumentException('The trusted live-probe operator keyring contains duplicate public-key material.');
            }

            $seenMaterial[$fingerprint] = true;
            $canonicalEncoded[$signerId] = $encodedKey;
            $decoded[$signerId] = $publicKey;
            $fingerprints[$signerId] = $fingerprint;
        }

        \ksort($canonicalEncoded, \SORT_STRING);
        \ksort($decoded, \SORT_STRING);
        \ksort($fingerprints, \SORT_STRING);

        return new self($canonicalEncoded, $decoded, $fingerprints);
    }

    /** @return non-empty-string */
    public function publicKey(#[SensitiveParameter] string $signerId): string
    {
        $publicKey = $this->decodedKeys[$signerId] ?? null;

        if (! \is_string($publicKey)) {
            throw new InvalidArgumentException('The live-probe authorization signer is not trusted by the operator keyring.');
        }

        return $publicKey;
    }

    /** @return non-empty-array<non-falsy-string, non-empty-string> */
    public function encodedKeys(): array
    {
        return $this->encodedKeys;
    }

    /**
     * Public-key fingerprints let the out-of-process authority prove that its
     * consumption-authority role is disjoint from the operator role.
     *
     * @return non-empty-array<non-falsy-string, non-empty-string>
     */
    public function fingerprints(): array
    {
        return $this->fingerprints;
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
