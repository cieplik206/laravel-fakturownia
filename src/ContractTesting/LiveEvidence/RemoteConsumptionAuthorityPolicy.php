<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;

final readonly class RemoteConsumptionAuthorityPolicy implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.remote-consumption-authority-policy';

    public const Version = '1';

    public const ClaimPath = '/api/fakturownia/live-evidence/consumption-claims/v1';

    public function __construct(
        #[SensitiveParameter] public string $authorityId,
        #[SensitiveParameter] public string $authorityPublicKey,
        #[SensitiveParameter] public string $authorityPublicKeySha256,
        #[SensitiveParameter] public string $storeId,
        #[SensitiveParameter] public string $storeIdentitySha256,
        #[SensitiveParameter] public string $endpoint,
        public int $connectTimeoutSeconds,
        public int $requestTimeoutSeconds,
        public int $maximumResponseBytes,
    ) {
        self::assertIdentifier($authorityId, 'authority ID');
        self::assertIdentifier($storeId, 'store ID');
        self::assertSodiumAvailable();

        $decodedAuthorityPublicKey = \base64_decode($authorityPublicKey, true);

        if ($decodedAuthorityPublicKey === false
            || \strlen($decodedAuthorityPublicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || \base64_encode($decodedAuthorityPublicKey) !== $authorityPublicKey
            || ! \hash_equals(\hash('sha256', $decodedAuthorityPublicKey), $authorityPublicKeySha256)) {
            throw new InvalidArgumentException('The remote authority public key or fingerprint is invalid.');
        }

        if (\preg_match('/^[a-f0-9]{64}$/D', $storeIdentitySha256) !== 1) {
            throw new InvalidArgumentException('The remote authority store identity SHA-256 is invalid.');
        }

        self::assertCanonicalEndpoint($endpoint);

        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 5
            || $requestTimeoutSeconds < $connectTimeoutSeconds
            || $requestTimeoutSeconds > 10
            || $maximumResponseBytes < 4_096
            || $maximumResponseBytes > 65_536) {
            throw new InvalidArgumentException('The remote authority transport limits are invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        $keys = \array_keys($value);
        \sort($keys);

        if ($keys !== [
            'authority_id',
            'authority_public_key',
            'authority_public_key_sha256',
            'connect_timeout_seconds',
            'endpoint',
            'maximum_response_bytes',
            'request_timeout_seconds',
            'store_id',
            'store_identity_sha256',
        ]) {
            throw new InvalidArgumentException('The remote authority policy contains missing or unknown fields.');
        }

        return new self(
            self::string($value, 'authority_id'),
            self::string($value, 'authority_public_key'),
            self::string($value, 'authority_public_key_sha256'),
            self::string($value, 'store_id'),
            self::string($value, 'store_identity_sha256'),
            self::string($value, 'endpoint'),
            self::integer($value, 'connect_timeout_seconds'),
            self::integer($value, 'request_timeout_seconds'),
            self::integer($value, 'maximum_response_bytes'),
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'authority_id' => $this->authorityId,
            'authority_public_key' => $this->authorityPublicKey,
            'authority_public_key_sha256' => $this->authorityPublicKeySha256,
            'store_id' => $this->storeId,
            'store_identity_sha256' => $this->storeIdentitySha256,
            'endpoint' => $this->endpoint,
            'connect_timeout_seconds' => $this->connectTimeoutSeconds,
            'request_timeout_seconds' => $this->requestTimeoutSeconds,
            'maximum_response_bytes' => $this->maximumResponseBytes,
        ];
    }

    public function sha256(): string
    {
        return \hash('sha256', CanonicalCodec::encode([
            'contract' => self::Contract,
            'version' => self::Version,
            'policy' => $this->toArray(),
        ]));
    }

    public function baseUrl(): string
    {
        $parts = \parse_url($this->endpoint);

        if (! \is_array($parts) || ! \is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('The pinned remote authority endpoint is malformed.');
        }

        return 'https://'.$parts['host'];
    }

    /** @return non-empty-string */
    public function decodedAuthorityPublicKey(): string
    {
        $publicKey = \base64_decode($this->authorityPublicKey, true);

        if (! \is_string($publicKey) || $publicKey === '') {
            throw new LogicException('The pinned remote authority public key is corrupted.');
        }

        return $publicKey;
    }

    /** @return array{authority: string, endpoint: string, public_key: string, store: string} */
    public function __debugInfo(): array
    {
        return [
            'authority' => '[REDACTED]',
            'endpoint' => '[REDACTED]',
            'public_key' => '[REDACTED]',
            'store' => '[REDACTED]',
        ];
    }

    /** @return array{authority: string, endpoint: string, public_key: string, store: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Remote authority policies cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Remote authority policies cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Remote authority policies cannot be unserialized.');
    }

    private static function assertCanonicalEndpoint(#[SensitiveParameter] string $endpoint): void
    {
        $parts = \parse_url($endpoint);

        if (! \is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! \is_string($parts['host'] ?? null)
            || ($parts['path'] ?? null) !== self::ClaimPath
            || isset($parts['user'], $parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The pinned remote authority endpoint must be an exact credential-free HTTPS URL.');
        }

        $host = \strtolower($parts['host']);

        if ($host !== $parts['host']
            || \filter_var($host, \FILTER_VALIDATE_IP) !== false
            || \strlen($host) > 253
            || \count(\explode('.', $host)) < 2
            || $endpoint !== "https://{$host}".self::ClaimPath) {
            throw new InvalidArgumentException('The pinned remote authority endpoint host is not canonical.');
        }

        foreach (\explode('.', $host) as $label) {
            if (\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                throw new InvalidArgumentException('The pinned remote authority endpoint host is invalid.');
            }
        }
    }

    private static function assertIdentifier(#[SensitiveParameter] string $value, string $label): void
    {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The remote authority {$label} is invalid.");
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(#[SensitiveParameter] array $value, string $key): string
    {
        if (! \is_string($value[$key] ?? null)) {
            throw new InvalidArgumentException("The remote authority policy field {$key} must be a string.");
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function integer(#[SensitiveParameter] array $value, string $key): int
    {
        if (! \is_int($value[$key] ?? null)) {
            throw new InvalidArgumentException("The remote authority policy field {$key} must be an integer.");
        }

        return $value[$key];
    }

    private static function assertSodiumAvailable(): void
    {
        if (! \extension_loaded('sodium')
            || ! \defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            throw new RuntimeException('Ed25519 verification requires the sodium extension.');
        }
    }
}
