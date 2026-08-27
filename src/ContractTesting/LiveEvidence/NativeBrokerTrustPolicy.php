<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use SensitiveParameter;

final readonly class NativeBrokerTrustPolicy implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.native-broker-trust-policy';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public const MaximumValiditySeconds = 31_536_000;

    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public string $brokerPolicySha256,
        public string $supervisorSemanticsSha256,
        public string $argvSha256,
        public string $environmentSha256,
        public int $probeUid,
        public int $probeGid,
        public string $supervisorSignerId,
        private string $supervisorPublicKey,
        public string $effectResultSignerId,
        private string $effectResultPublicKey,
        public string $signature,
    ) {}

    /** @param array<string, mixed> $document */
    public static function verify(
        #[SensitiveParameter] array $document,
        string $expectedSignerId,
        #[SensitiveParameter] string $trustedPolicyPublicKeyBase64,
        DateTimeImmutable $observedAt,
    ): self {
        self::assertSodiumAvailable();
        NativeBrokerWireValidation::assertIdentifier($expectedSignerId, 'native broker policy signer');
        NativeBrokerWireValidation::assertExactKeys(
            $document,
            ['envelope', 'signature'],
            'native broker trust policy',
        );
        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! \is_array($envelope)
            || \array_is_list($envelope)
            || ! \is_string($signature)) {
            throw new InvalidArgumentException('The native broker trust policy must contain one envelope and signature.');
        }

        NativeBrokerWireValidation::assertExactKeys($envelope, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'broker_policy_sha256',
            'supervisor_semantics_sha256',
            'argv_sha256',
            'environment_sha256',
            'probe_uid',
            'probe_gid',
            'supervisor_signer',
            'effect_result_signer',
        ], 'native broker trust policy envelope');

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm) {
            throw new InvalidArgumentException('The native broker trust policy must use the exact version 1 contract.');
        }

        $supervisorSigner = self::signer($envelope, 'supervisor_signer');
        $effectResultSigner = self::signer($envelope, 'effect_result_signer');
        $policy = new self(
            NativeBrokerWireValidation::string($envelope, 'signer_id', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'issued_at', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'expires_at', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'broker_policy_sha256', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'supervisor_semantics_sha256', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'argv_sha256', 'native broker trust policy'),
            NativeBrokerWireValidation::string($envelope, 'environment_sha256', 'native broker trust policy'),
            NativeBrokerWireValidation::integer($envelope, 'probe_uid', 'native broker trust policy'),
            NativeBrokerWireValidation::integer($envelope, 'probe_gid', 'native broker trust policy'),
            $supervisorSigner['id'],
            $supervisorSigner['public_key'],
            $effectResultSigner['id'],
            $effectResultSigner['public_key'],
            $signature,
        );
        $policy->assertValid($expectedSignerId, $trustedPolicyPublicKeyBase64, $observedAt, $envelope);

        return $policy;
    }

    public function assertValidAt(DateTimeImmutable $observedAt): void
    {
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->issuedAt,
            'native broker trust policy issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->expiresAt,
            'native broker trust policy expiry time',
        );

        if ($issuedAt > $observedAt || $expiresAt <= $observedAt) {
            throw new InvalidArgumentException('The native broker trust policy is not valid at the observation time.');
        }
    }

    public function assertSupervisorAttestationSignature(
        #[SensitiveParameter] NativeSupervisorAttestation $attestation,
    ): void {
        if (! \hash_equals($this->supervisorSignerId, $attestation->signerId)) {
            throw new InvalidArgumentException('The native supervisor attestation signer is not trusted for this role.');
        }

        self::assertDetachedSignature(
            $attestation->signature,
            $attestation->canonicalEnvelope(),
            $this->supervisorPublicKey,
            'native supervisor attestation',
        );
    }

    public function assertEffectExecutionResultSignature(
        #[SensitiveParameter] BrokeredEffectExecutionResult $result,
    ): void {
        if (! \hash_equals($this->effectResultSignerId, $result->signerId)) {
            throw new InvalidArgumentException('The brokered effect result signer is not trusted for this role.');
        }

        self::assertDetachedSignature(
            $result->signature,
            $result->canonicalEnvelope(),
            $this->effectResultPublicKey,
            'brokered effect execution result',
        );
    }

    /** @return array{native_broker_trust_policy: string, public_keys: string} */
    public function __debugInfo(): array
    {
        return [
            'native_broker_trust_policy' => '[VERIFIED]',
            'public_keys' => '[REDACTED]',
        ];
    }

    /** @return array{native_broker_trust_policy: string, public_keys: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Native broker trust policies cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Native broker trust policies cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Native broker trust policies cannot be unserialized.');
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertValid(
        string $expectedSignerId,
        #[SensitiveParameter] string $trustedPolicyPublicKeyBase64,
        DateTimeImmutable $observedAt,
        #[SensitiveParameter] array $envelope,
    ): void {
        NativeBrokerWireValidation::assertIdentifier($this->signerId, 'native broker policy signer');

        if (! \hash_equals($expectedSignerId, $this->signerId)) {
            throw new InvalidArgumentException('The native broker trust policy signer is not the pinned policy authority.');
        }

        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->issuedAt,
            'native broker trust policy issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->expiresAt,
            'native broker trust policy expiry time',
        );
        NativeBrokerWireValidation::assertBoundedValidity(
            $issuedAt,
            $expiresAt,
            self::MaximumValiditySeconds,
            'native broker trust policy',
        );
        $this->assertValidAt($observedAt);

        foreach ([
            'broker policy' => $this->brokerPolicySha256,
            'supervisor semantics' => $this->supervisorSemanticsSha256,
            'argv contract' => $this->argvSha256,
            'environment contract' => $this->environmentSha256,
        ] as $context => $sha256) {
            NativeBrokerWireValidation::assertSha256($sha256, $context);
        }

        if ($this->probeUid < 1
            || $this->probeUid > 4_294_967_294
            || $this->probeGid < 1
            || $this->probeGid > 4_294_967_294) {
            throw new InvalidArgumentException('The native broker policy probe identity must be a non-root 32-bit UID/GID.');
        }

        NativeBrokerWireValidation::assertIdentifier($this->supervisorSignerId, 'native supervisor signer');
        NativeBrokerWireValidation::assertIdentifier($this->effectResultSignerId, 'brokered effect result signer');

        if (\hash_equals($this->supervisorSignerId, $this->effectResultSignerId)) {
            throw new InvalidArgumentException('Native supervisor and effect-result signer identities must be disjoint.');
        }

        $policyPublicKey = NativeBrokerWireValidation::decodeCanonicalBase64(
            $trustedPolicyPublicKeyBase64,
            \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'native broker policy public key',
        );
        $supervisorPublicKey = self::publicKey($this->supervisorPublicKey, 'native supervisor public key');
        $effectResultPublicKey = self::publicKey($this->effectResultPublicKey, 'brokered effect result public key');
        $fingerprints = [
            \hash('sha256', $policyPublicKey),
            \hash('sha256', $supervisorPublicKey),
            \hash('sha256', $effectResultPublicKey),
        ];

        if (\count(\array_unique($fingerprints)) !== \count($fingerprints)) {
            throw new InvalidArgumentException('Native broker policy, supervisor and effect-result roles must not reuse Ed25519 keys.');
        }

        self::assertDetachedSignature(
            $this->signature,
            CanonicalCodec::encode($envelope),
            $trustedPolicyPublicKeyBase64,
            'native broker trust policy',
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{id: string, public_key: string}
     */
    private static function signer(#[SensitiveParameter] array $envelope, string $key): array
    {
        $signer = $envelope[$key] ?? null;

        if (! \is_array($signer) || \array_is_list($signer)) {
            throw new InvalidArgumentException("The native broker trust policy field {$key} must be an object.");
        }

        NativeBrokerWireValidation::assertExactKeys(
            $signer,
            ['id', 'algorithm', 'public_key'],
            "native broker trust policy {$key}",
        );

        if (($signer['algorithm'] ?? null) !== self::Algorithm) {
            throw new InvalidArgumentException("The native broker trust policy {$key} algorithm is invalid.");
        }

        return [
            'id' => NativeBrokerWireValidation::string($signer, 'id', "native broker trust policy {$key}"),
            'public_key' => NativeBrokerWireValidation::string($signer, 'public_key', "native broker trust policy {$key}"),
        ];
    }

    private static function assertDetachedSignature(
        #[SensitiveParameter] string $signatureBase64,
        #[SensitiveParameter] string $message,
        #[SensitiveParameter] string $publicKeyBase64,
        string $context,
    ): void {
        self::assertSodiumAvailable();
        $signature = NativeBrokerWireValidation::decodeCanonicalBase64(
            $signatureBase64,
            \SODIUM_CRYPTO_SIGN_BYTES,
            "{$context} signature",
        );
        $publicKey = self::publicKey($publicKeyBase64, "{$context} public key");

        if (\strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES
            || ! \sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new InvalidArgumentException("The {$context} signature is invalid.");
        }
    }

    /** @return non-empty-string */
    private static function publicKey(
        #[SensitiveParameter] string $publicKeyBase64,
        string $context,
    ): string {
        $publicKey = NativeBrokerWireValidation::decodeCanonicalBase64(
            $publicKeyBase64,
            \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            $context,
        );

        if (\strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException("The {$context} has an invalid byte length.");
        }

        return $publicKey;
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
