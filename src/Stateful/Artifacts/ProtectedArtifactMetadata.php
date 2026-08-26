<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ProtectedArtifactMetadata
{
    use RejectsNativeSerialization;

    public function __construct(
        public int $keyVersion,
        public string $cipher,
        public string $ciphertext,
        public string $ciphertextSha256,
    ) {
        if ($keyVersion < 1
            || $keyVersion > 65_535
            || $cipher !== 'AES-256-GCM'
            || $ciphertext === ''
            || strlen($ciphertext) > 16_384
            || preg_match('/^[A-Za-z0-9+\/=.]+$/D', $ciphertext) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $ciphertextSha256) !== 1
            || ! hash_equals(hash('sha256', $ciphertext), $ciphertextSha256)) {
            throw new InvalidArgumentException('The protected artifact metadata envelope is invalid.');
        }
    }

    /** @return array{key_version: int, cipher: string, ciphertext: string} */
    public function __debugInfo(): array
    {
        return [
            'key_version' => $this->keyVersion,
            'cipher' => $this->cipher,
            'ciphertext' => '[REDACTED]',
        ];
    }
}
