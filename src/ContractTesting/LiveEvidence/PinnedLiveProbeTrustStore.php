<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;

final readonly class PinnedLiveProbeTrustStore implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.trusted-operator-signers';

    public const Version = '1';

    public const RepositoryPath = 'tests/Fixtures/Contract/trusted-operator-signers.json';

    private const OperatorRole = 'operator_attestation';

    private const AuthorityRole = 'consumption_authority';

    /**
     * @param  non-empty-array<non-falsy-string, non-falsy-string>  $operatorKeys
     * @param  non-empty-array<non-falsy-string, non-falsy-string>  $authorityKeys
     * @param  non-empty-array<non-falsy-string, non-falsy-string>  $authorityFingerprints
     */
    private function __construct(
        private array $operatorKeys,
        private array $authorityKeys,
        private array $authorityFingerprints,
    ) {}

    public static function load(#[SensitiveParameter] string $verifiedRepositoryRoot): self
    {
        self::assertSodiumAvailable();
        $contents = PinnedRepositorySnapshotReader::read($verifiedRepositoryRoot, self::RepositoryPath);

        try {
            $document = \json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The pinned live-probe trust store is invalid JSON.');
        }

        if (! \is_array($document)
            || \array_is_list($document)
            || ! self::hasExactKeys($document, ['contract', 'version', 'signers'])
            || ($document['contract'] ?? null) !== self::Contract
            || ($document['version'] ?? null) !== self::Version
            || ! \is_array($document['signers'] ?? null)
            || ! \array_is_list($document['signers'])
            || $document['signers'] === []
            || \count($document['signers']) > 128) {
            throw new RuntimeException('The pinned live-probe trust store has an invalid exact contract.');
        }

        $operatorKeys = [];
        $authorityKeys = [];
        $authorityFingerprints = [];
        $seenIds = [];
        $seenFingerprints = [];

        foreach ($document['signers'] as $signer) {
            if (! \is_array($signer)
                || \array_is_list($signer)
                || ! self::hasExactKeys($signer, ['id', 'algorithm', 'public_key', 'roles'])
                || ! \is_string($signer['id'] ?? null)
                || \preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $signer['id']) !== 1
                || ($signer['algorithm'] ?? null) !== SignedLiveProbeAuthorization::Algorithm
                || ! \is_string($signer['public_key'] ?? null)
                || ! \is_array($signer['roles'] ?? null)
                || ! \array_is_list($signer['roles'])
                || \count($signer['roles']) !== 1
                || ! \is_string($signer['roles'][0] ?? null)
                || ! \in_array($signer['roles'][0], [self::OperatorRole, self::AuthorityRole], true)
                || isset($seenIds[$signer['id']])) {
                throw new RuntimeException('The pinned live-probe trust store contains an invalid signer.');
            }

            $publicKey = \base64_decode($signer['public_key'], true);

            if ($publicKey === false
                || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || \base64_encode($publicKey) !== $signer['public_key']) {
                throw new RuntimeException('The pinned live-probe trust store contains an invalid Ed25519 public key.');
            }

            $fingerprint = \hash('sha256', $publicKey);

            if (isset($seenFingerprints[$fingerprint])) {
                throw new RuntimeException('The pinned live-probe trust store reuses public-key material across roles or identities.');
            }

            $seenIds[$signer['id']] = true;
            $seenFingerprints[$fingerprint] = true;

            if ($signer['roles'][0] === self::OperatorRole) {
                $operatorKeys[$signer['id']] = $signer['public_key'];

                continue;
            }

            $authorityKeys[$signer['id']] = $signer['public_key'];
            $authorityFingerprints[$signer['id']] = $fingerprint;
        }

        if ($operatorKeys === [] || $authorityKeys === []) {
            throw new RuntimeException('The pinned live-probe trust store must provision disjoint operator and authority roles.');
        }

        \ksort($operatorKeys, \SORT_STRING);
        \ksort($authorityKeys, \SORT_STRING);
        \ksort($authorityFingerprints, \SORT_STRING);

        /** @var non-empty-array<non-falsy-string, non-falsy-string> $operatorKeys */
        /** @var non-empty-array<non-falsy-string, non-falsy-string> $authorityKeys */
        /** @var non-empty-array<non-falsy-string, non-falsy-string> $authorityFingerprints */
        return new self($operatorKeys, $authorityKeys, $authorityFingerprints);
    }

    public function operatorKeyring(): TrustedLiveProbeOperatorKeys
    {
        return TrustedLiveProbeOperatorKeys::fromBase64Map($this->operatorKeys);
    }

    public function assertAuthorityMatches(#[SensitiveParameter] RemoteConsumptionAuthorityPolicy $policy): void
    {
        $encodedKey = $this->authorityKeys[$policy->authorityId] ?? null;
        $fingerprint = $this->authorityFingerprints[$policy->authorityId] ?? null;

        if (! \is_string($encodedKey)
            || ! \is_string($fingerprint)
            || ! \hash_equals($encodedKey, $policy->authorityPublicKey)
            || ! \hash_equals($fingerprint, $policy->authorityPublicKeySha256)
            || \array_key_exists($policy->authorityId, $this->operatorKeys)) {
            throw new InvalidArgumentException('The remote authority policy does not match the exact disjoint pinned trust role.');
        }
    }

    /** @return array{operators: string, authorities: string, public_keys: string} */
    public function __debugInfo(): array
    {
        return [
            'operators' => '[REDACTED]',
            'authorities' => '[REDACTED]',
            'public_keys' => '[REDACTED]',
        ];
    }

    /** @return array{operators: string, authorities: string, public_keys: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Pinned live-probe trust stores cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Pinned live-probe trust stores cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Pinned live-probe trust stores cannot be unserialized.');
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function hasExactKeys(
        #[SensitiveParameter] array $value,
        array $expected,
    ): bool {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);
        \sort($expected, \SORT_STRING);

        return $keys === $expected;
    }

    private static function assertSodiumAvailable(): void
    {
        if (! \extension_loaded('sodium')
            || ! \defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            throw new RuntimeException('Ed25519 verification requires the sodium extension.');
        }
    }
}
