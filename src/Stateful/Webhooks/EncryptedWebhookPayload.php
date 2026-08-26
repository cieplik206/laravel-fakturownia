<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use InvalidArgumentException;
use LogicException;

final readonly class EncryptedWebhookPayload
{
    public const Algorithm = 'XCHACHA20-POLY1305';

    public const MaximumCiphertextBytes = 1_398_124;

    public function __construct(
        public int $keyVersion,
        public string $algorithm,
        public string $nonceBase64,
        public string $ciphertextBase64,
        public string $ciphertextSha256,
    ) {
        if ($keyVersion < 1 || $keyVersion > 65_535) {
            throw new InvalidArgumentException('The webhook encryption key version is invalid.');
        }

        if ($algorithm !== self::Algorithm) {
            throw new InvalidArgumentException('The webhook encryption algorithm is unsupported.');
        }

        $nonce = sodium_base642bin($nonceBase64, SODIUM_BASE64_VARIANT_ORIGINAL, '');

        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_ORIGINAL) !== $nonceBase64) {
            throw new InvalidArgumentException('The webhook encryption nonce is not canonical.');
        }

        $ciphertext = sodium_base642bin($ciphertextBase64, SODIUM_BASE64_VARIANT_ORIGINAL, '');

        if (strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 1
            || strlen($ciphertextBase64) > self::MaximumCiphertextBytes
            || sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL) !== $ciphertextBase64) {
            throw new InvalidArgumentException('The encrypted webhook payload is invalid.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $ciphertextSha256) !== 1
            || ! hash_equals(hash('sha256', $ciphertextBase64), $ciphertextSha256)) {
            throw new InvalidArgumentException('The encrypted webhook payload checksum is invalid.');
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Encrypted webhook payloads cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Encrypted webhook payloads cannot be unserialized.');
    }
}
