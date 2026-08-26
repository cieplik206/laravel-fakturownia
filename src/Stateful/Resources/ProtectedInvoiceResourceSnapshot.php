<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class ProtectedInvoiceResourceSnapshot
{
    use RejectsNativeSerialization;

    private const int MaximumCiphertextBytes = 524_288;

    public function __construct(
        public int $snapshotSchemaVersion,
        public int $encryptionKeyVersion,
        public string $cipher,
        public string $nonceBase64,
        public string $ciphertextBase64,
        public string $ciphertextSha256,
        public VersionedHmacDigest $fingerprint,
    ) {
        if ($snapshotSchemaVersion !== InvoiceResourceProjectionPlan::SnapshotSchemaVersion
            || $encryptionKeyVersion < 1
            || $cipher !== 'XCHACHA20-POLY1305'
            || $fingerprint->domain !== LookupHmacDomain::Payload) {
            throw new InvalidArgumentException('Protected invoice resource snapshot metadata is invalid.');
        }

        $nonce = $this->decodeCanonicalBase64($nonceBase64, 'nonce');
        $ciphertext = $this->decodeCanonicalBase64($ciphertextBase64, 'ciphertext');

        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || strlen($ciphertext) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            || strlen($ciphertextBase64) > self::MaximumCiphertextBytes
            || preg_match('/^[a-f0-9]{64}$/D', $ciphertextSha256) !== 1
            || ! hash_equals(hash('sha256', $ciphertextBase64), $ciphertextSha256)) {
            throw new InvalidArgumentException('Protected invoice resource snapshot envelope is invalid.');
        }
    }

    /** @return array{schema: int, key_version: int, cipher: string, ciphertext: string, fingerprint: string} */
    public function __debugInfo(): array
    {
        return [
            'schema' => $this->snapshotSchemaVersion,
            'key_version' => $this->encryptionKeyVersion,
            'cipher' => $this->cipher,
            'ciphertext' => '[REDACTED]',
            'fingerprint' => '[REDACTED]',
        ];
    }

    private function decodeCanonicalBase64(string $value, string $field): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Protected invoice resource snapshot {$field} is invalid.");
        }

        $decoded = base64_decode($value, true);

        if (! is_string($decoded) || base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException("Protected invoice resource snapshot {$field} is not canonical base64.");
        }

        return $decoded;
    }
}
