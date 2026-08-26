<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactMetadataProtector;
use Cieplik206\Fakturownia\Stateful\Artifacts\ProtectedArtifactMetadata;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;

final class AesGcmArtifactMetadataProtector implements ArtifactMetadataProtector
{
    private const string Cipher = 'aes-256-gcm';

    private const string CipherLabel = 'AES-256-GCM';

    private const string Protocol = 'cieplik206.fakturownia.artifact-metadata.v1';

    public function __construct(private readonly Repository $configuration) {}

    public function protect(
        ArtifactProjectionPlan $plan,
        string $purpose,
        string $plaintext,
    ): ProtectedArtifactMetadata {
        $this->assertInput($purpose, $plaintext);
        $initializationVector = random_bytes(12);
        $authenticationTag = '';
        $activeVersion = $this->activeVersion();
        $key = $this->key($activeVersion);

        try {
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::Cipher,
                $key,
                OPENSSL_RAW_DATA,
                $initializationVector,
                $authenticationTag,
                $this->associatedData($plan, $purpose),
                16,
            );

            if (! is_string($ciphertext) || strlen($authenticationTag) !== 16) {
                throw new LogicException('Artifact metadata encryption failed.');
            }

            $envelope = base64_encode($initializationVector)
                .'.'.base64_encode($authenticationTag)
                .'.'.base64_encode($ciphertext);

            return new ProtectedArtifactMetadata(
                $activeVersion,
                self::CipherLabel,
                $envelope,
                hash('sha256', $envelope),
            );
        } finally {
            sodium_memzero($key);
        }
    }

    public function recover(
        ArtifactProjectionPlan $plan,
        string $purpose,
        ProtectedArtifactMetadata $metadata,
    ): string {
        $this->assertPurpose($purpose);
        [$initializationVector, $authenticationTag, $ciphertext] = $this->decodeEnvelope($metadata->ciphertext);
        $key = $this->key($metadata->keyVersion);

        try {
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::Cipher,
                $key,
                OPENSSL_RAW_DATA,
                $initializationVector,
                $authenticationTag,
                $this->associatedData($plan, $purpose),
            );
        } finally {
            sodium_memzero($key);
        }

        if (! is_string($plaintext) || $plaintext === '') {
            throw new InvalidArgumentException('The artifact metadata cannot be authenticated.');
        }

        return $plaintext;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Artifact metadata protectors cannot be serialized.');
    }

    /** @return array{keys: string, active_version: string} */
    public function __debugInfo(): array
    {
        return ['keys' => '[REDACTED]', 'active_version' => '[CONFIGURED]'];
    }

    /** @return array<int, string> */
    private function configuredKeys(): array
    {
        $keys = $this->configuration->get('fakturownia.artifacts.encryption.keys', []);

        if (! is_array($keys) || $keys === []) {
            throw new InvalidArgumentException('Artifact metadata encryption keys are not configured.');
        }

        $validated = [];

        foreach ($keys as $version => $key) {
            if ((! is_int($version) && ! ctype_digit((string) $version)) || ! is_string($key)) {
                throw new InvalidArgumentException('An artifact metadata encryption key entry is invalid.');
            }

            $validated[(int) $version] = $key;
        }

        return $validated;
    }

    private function activeVersion(): int
    {
        $version = $this->configuration->get('fakturownia.artifacts.encryption.active_version', 1);

        if (! is_int($version) || $version < 1 || $version > 65_535) {
            throw new InvalidArgumentException('The active artifact metadata encryption key version is invalid.');
        }

        return $version;
    }

    private function decodeKey(string $encoded): string
    {
        if (str_starts_with($encoded, 'base64:')) {
            $encoded = substr($encoded, 7);
        }

        $key = base64_decode($encoded, true);

        if (! is_string($key) || strlen($key) !== 32 || base64_encode($key) !== $encoded) {
            throw new InvalidArgumentException('An artifact metadata encryption key must be canonical base64 for 32 bytes.');
        }

        return $key;
    }

    private function key(int $version): string
    {
        $encoded = $this->configuredKeys()[$version] ?? null;

        if (! is_string($encoded)) {
            throw new InvalidArgumentException('The artifact metadata encryption key version is unavailable.');
        }

        $key = $this->decodeKey($encoded);

        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The artifact metadata encryption key version is unavailable.');
        }

        return $key;
    }

    private function assertInput(string $purpose, string $plaintext): void
    {
        $this->assertPurpose($purpose);

        if ($plaintext === '' || strlen($plaintext) > 4_096 || preg_match('//u', $plaintext) !== 1) {
            throw new InvalidArgumentException('The artifact metadata plaintext is invalid.');
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('The artifact metadata purpose is invalid.');
        }
    }

    /** @return array{string, string, string} */
    private function decodeEnvelope(string $envelope): array
    {
        $parts = explode('.', $envelope);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException('The artifact metadata envelope shape is invalid.');
        }

        $decoded = array_map(static function (string $part): string {
            $bytes = base64_decode($part, true);

            if (! is_string($bytes) || base64_encode($bytes) !== $part) {
                throw new InvalidArgumentException('The artifact metadata envelope is not canonical base64.');
            }

            return $bytes;
        }, $parts);

        if (strlen($decoded[0]) !== 12 || strlen($decoded[1]) !== 16 || $decoded[2] === '') {
            throw new InvalidArgumentException('The artifact metadata envelope dimensions are invalid.');
        }

        return [$decoded[0], $decoded[1], $decoded[2]];
    }

    private function associatedData(ArtifactProjectionPlan $plan, string $purpose): string
    {
        return $this->frame(self::Protocol)
            .$this->frame($purpose)
            .$this->frame($plan->artifactId->value)
            .$this->frame($plan->connectionKey->value)
            .$this->frame($plan->operationId->value)
            .$this->frame($plan->resourceId->value)
            .$this->frame($plan->revisionKeyHmac);
    }

    private function frame(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
