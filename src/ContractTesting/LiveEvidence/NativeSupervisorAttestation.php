<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class NativeSupervisorAttestation implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.native-supervisor-attestation';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public const MaximumValiditySeconds = 600;

    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public string $launchManifestSha256,
        public string $runNonce,
        public string $authorizationSetSha256,
        public string $brokerPolicySha256,
        public string $supervisorSemanticsSha256,
        public string $argvSha256,
        public string $environmentSha256,
        public int $probeUid,
        public int $probeGid,
        public string $signature,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(#[SensitiveParameter] array $document): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $document,
            ['envelope', 'signature'],
            'native supervisor attestation',
        );
        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! \is_array($envelope)
            || \array_is_list($envelope)
            || ! \is_string($signature)) {
            throw new InvalidArgumentException('The native supervisor attestation must contain one envelope and signature.');
        }

        NativeBrokerWireValidation::assertExactKeys($envelope, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'launch_manifest_sha256',
            'run_nonce',
            'authorization_set_sha256',
            'broker_policy_sha256',
            'supervisor_semantics_sha256',
            'argv_sha256',
            'environment_sha256',
            'probe_uid',
            'probe_gid',
        ], 'native supervisor attestation envelope');

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm) {
            throw new InvalidArgumentException('The native supervisor attestation must use the exact version 1 contract.');
        }

        $attestation = new self(
            NativeBrokerWireValidation::string($envelope, 'signer_id', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'issued_at', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'expires_at', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'launch_manifest_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'run_nonce', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'authorization_set_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'broker_policy_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'supervisor_semantics_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'argv_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::string($envelope, 'environment_sha256', 'native supervisor attestation'),
            NativeBrokerWireValidation::integer($envelope, 'probe_uid', 'native supervisor attestation'),
            NativeBrokerWireValidation::integer($envelope, 'probe_gid', 'native supervisor attestation'),
            $signature,
        );
        $attestation->assertValid();

        return $attestation;
    }

    /** @return array<string, int|string> */
    public function envelope(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $this->signerId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'launch_manifest_sha256' => $this->launchManifestSha256,
            'run_nonce' => $this->runNonce,
            'authorization_set_sha256' => $this->authorizationSetSha256,
            'broker_policy_sha256' => $this->brokerPolicySha256,
            'supervisor_semantics_sha256' => $this->supervisorSemanticsSha256,
            'argv_sha256' => $this->argvSha256,
            'environment_sha256' => $this->environmentSha256,
            'probe_uid' => $this->probeUid,
            'probe_gid' => $this->probeGid,
        ];
    }

    /** @return array{envelope: array<string, int|string>, signature: string} */
    public function toArray(): array
    {
        return [
            'envelope' => $this->envelope(),
            'signature' => $this->signature,
        ];
    }

    public function canonicalEnvelope(): string
    {
        return CanonicalCodec::encode($this->envelope());
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    public function sha256(): string
    {
        return \hash('sha256', $this->canonical());
    }

    /** @return array{native_supervisor_attestation: string} */
    public function __debugInfo(): array
    {
        return ['native_supervisor_attestation' => '[REDACTED]'];
    }

    /** @return array{native_supervisor_attestation: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Native supervisor attestations cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Native supervisor attestations cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Native supervisor attestations cannot be unserialized.');
    }

    private function assertValid(): void
    {
        NativeBrokerWireValidation::assertIdentifier($this->signerId, 'native supervisor signer');
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->issuedAt,
            'native supervisor issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->expiresAt,
            'native supervisor expiry time',
        );
        NativeBrokerWireValidation::assertBoundedValidity(
            $issuedAt,
            $expiresAt,
            self::MaximumValiditySeconds,
            'native supervisor attestation',
        );

        foreach ([
            'launch manifest' => $this->launchManifestSha256,
            'authorization set' => $this->authorizationSetSha256,
            'broker policy' => $this->brokerPolicySha256,
            'supervisor semantics' => $this->supervisorSemanticsSha256,
            'argv contract' => $this->argvSha256,
            'environment contract' => $this->environmentSha256,
        ] as $context => $sha256) {
            NativeBrokerWireValidation::assertSha256($sha256, $context);
        }

        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->runNonce, 32, 'native supervisor run nonce');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->signature, 64, 'native supervisor signature');

        if ($this->probeUid < 1
            || $this->probeUid > 4_294_967_294
            || $this->probeGid < 1
            || $this->probeGid > 4_294_967_294) {
            throw new InvalidArgumentException('The native supervisor probe identity must be a non-root 32-bit UID/GID.');
        }
    }
}
