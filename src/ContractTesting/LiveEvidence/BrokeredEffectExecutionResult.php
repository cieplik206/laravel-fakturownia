<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredEffectExecutionResult implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-effect-execution-result';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public const MaximumValiditySeconds = 600;

    public const MaximumResponseBytes = 1_048_576;

    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public string $launchManifestSha256,
        public string $runNonce,
        public string $authorizationSetSha256,
        public string $brokerPolicySha256,
        public string $supervisorAttestationSha256,
        public string $effectDescriptorSha256,
        public string $effectId,
        public string $casRecordSha256,
        public BrokeredEffectDisposition $disposition,
        public ?string $requestStartedAt,
        public ?string $responseReceivedAt,
        public int $httpStatus,
        public ?string $contentType,
        public ?string $providerRequestIdHmacSha256,
        public string $responseBodyBase64,
        public string $responseBodySha256,
        public int $responseSizeBytes,
        public string $signature,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(#[SensitiveParameter] array $document): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $document,
            ['envelope', 'signature'],
            'brokered effect execution result',
        );
        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! \is_array($envelope)
            || \array_is_list($envelope)
            || ! \is_string($signature)) {
            throw new InvalidArgumentException('The brokered effect execution result must contain one envelope and signature.');
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
            'supervisor_attestation_sha256',
            'effect_descriptor_sha256',
            'effect_id',
            'cas_record_sha256',
            'disposition',
            'request_started_at',
            'response_received_at',
            'http_status',
            'content_type',
            'provider_request_id_hmac_sha256',
            'response_body_base64',
            'response_body_sha256',
            'response_size_bytes',
        ], 'brokered effect execution result envelope');

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! \is_string($envelope['disposition'] ?? null)) {
            throw new InvalidArgumentException('The brokered effect execution result must use the exact version 1 contract.');
        }

        $disposition = BrokeredEffectDisposition::tryFrom($envelope['disposition']);

        if (! $disposition instanceof BrokeredEffectDisposition) {
            throw new InvalidArgumentException('The brokered effect execution disposition is invalid.');
        }

        $result = new self(
            NativeBrokerWireValidation::string($envelope, 'signer_id', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'issued_at', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'expires_at', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'launch_manifest_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'run_nonce', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'authorization_set_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'broker_policy_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'supervisor_attestation_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'effect_descriptor_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'effect_id', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'cas_record_sha256', 'brokered effect execution result'),
            $disposition,
            self::nullableString($envelope, 'request_started_at'),
            self::nullableString($envelope, 'response_received_at'),
            NativeBrokerWireValidation::integer($envelope, 'http_status', 'brokered effect execution result'),
            self::nullableString($envelope, 'content_type'),
            self::nullableString($envelope, 'provider_request_id_hmac_sha256'),
            NativeBrokerWireValidation::string($envelope, 'response_body_base64', 'brokered effect execution result'),
            NativeBrokerWireValidation::string($envelope, 'response_body_sha256', 'brokered effect execution result'),
            NativeBrokerWireValidation::integer($envelope, 'response_size_bytes', 'brokered effect execution result'),
            $signature,
        );
        $result->assertValid();

        return $result;
    }

    /** @return array<string, int|string|null> */
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
            'supervisor_attestation_sha256' => $this->supervisorAttestationSha256,
            'effect_descriptor_sha256' => $this->effectDescriptorSha256,
            'effect_id' => $this->effectId,
            'cas_record_sha256' => $this->casRecordSha256,
            'disposition' => $this->disposition->value,
            'request_started_at' => $this->requestStartedAt,
            'response_received_at' => $this->responseReceivedAt,
            'http_status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'provider_request_id_hmac_sha256' => $this->providerRequestIdHmacSha256,
            'response_body_base64' => $this->responseBodyBase64,
            'response_body_sha256' => $this->responseBodySha256,
            'response_size_bytes' => $this->responseSizeBytes,
        ];
    }

    /** @return array{envelope: array<string, int|string|null>, signature: string} */
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

    public function responseBody(): string
    {
        return NativeBrokerWireValidation::decodeCanonicalBase64(
            $this->responseBodyBase64,
            self::MaximumResponseBytes,
            'brokered effect response body',
        );
    }

    /** @return array{brokered_effect_execution_result: string} */
    public function __debugInfo(): array
    {
        return ['brokered_effect_execution_result' => '[REDACTED]'];
    }

    /** @return array{brokered_effect_execution_result: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered effect execution results cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered effect execution results cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered effect execution results cannot be unserialized.');
    }

    private function assertValid(): void
    {
        NativeBrokerWireValidation::assertIdentifier($this->signerId, 'brokered effect signer');
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->issuedAt,
            'brokered effect issue time',
        );
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant(
            $this->expiresAt,
            'brokered effect expiry time',
        );
        NativeBrokerWireValidation::assertBoundedValidity(
            $issuedAt,
            $expiresAt,
            self::MaximumValiditySeconds,
            'brokered effect execution result',
        );

        foreach ([
            'launch manifest' => $this->launchManifestSha256,
            'authorization set' => $this->authorizationSetSha256,
            'broker policy' => $this->brokerPolicySha256,
            'supervisor attestation' => $this->supervisorAttestationSha256,
            'effect descriptor' => $this->effectDescriptorSha256,
            'CAS record' => $this->casRecordSha256,
            'response body' => $this->responseBodySha256,
        ] as $context => $sha256) {
            NativeBrokerWireValidation::assertSha256($sha256, $context);
        }

        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->runNonce, 32, 'brokered effect run nonce');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->signature, 64, 'brokered effect signature');

        if (\preg_match('/^[a-f0-9]{32}$/D', $this->effectId) !== 1) {
            throw new InvalidArgumentException('The brokered effect identity is invalid.');
        }

        $responseBody = $this->responseBody();

        if (\strlen($responseBody) !== $this->responseSizeBytes
            || ! \hash_equals(\hash('sha256', $responseBody), $this->responseBodySha256)) {
            throw new InvalidArgumentException('The brokered effect response body binding is invalid.');
        }

        if ($this->contentType !== null
            && (\strlen($this->contentType) > 255
                || \preg_match('/^[\x20-\x7e]+$/D', $this->contentType) !== 1)) {
            throw new InvalidArgumentException('The brokered effect content type is invalid.');
        }

        if ($this->providerRequestIdHmacSha256 !== null) {
            NativeBrokerWireValidation::assertSha256(
                $this->providerRequestIdHmacSha256,
                'provider request ID HMAC',
            );
        }

        $this->assertDispositionShape();
    }

    private function assertDispositionShape(): void
    {
        if ($this->disposition === BrokeredEffectDisposition::Applied) {
            if ($this->requestStartedAt === null
                || $this->responseReceivedAt === null
                || $this->httpStatus < 100
                || $this->httpStatus > 599) {
                throw new InvalidArgumentException('An applied brokered effect requires bounded request and response evidence.');
            }

            NativeBrokerWireValidation::strictUtcMicrosecondInstant(
                $this->requestStartedAt,
                'brokered effect request start',
            );
            NativeBrokerWireValidation::strictUtcMicrosecondInstant(
                $this->responseReceivedAt,
                'brokered effect response time',
            );

            return;
        }

        if ($this->disposition === BrokeredEffectDisposition::PossiblyApplied) {
            if ($this->requestStartedAt === null
                || $this->responseReceivedAt !== null
                || $this->httpStatus !== 0
                || $this->responseSizeBytes !== 0
                || $this->contentType !== null
                || $this->providerRequestIdHmacSha256 !== null) {
                throw new InvalidArgumentException('A possibly-applied brokered effect must not claim a provider response.');
            }

            NativeBrokerWireValidation::strictUtcMicrosecondInstant(
                $this->requestStartedAt,
                'brokered effect request start',
            );

            return;
        }

        if ($this->requestStartedAt !== null
            || $this->responseReceivedAt !== null
            || $this->httpStatus !== 0
            || $this->responseSizeBytes !== 0
            || $this->contentType !== null
            || $this->providerRequestIdHmacSha256 !== null) {
            throw new InvalidArgumentException('A non-executed brokered effect must not claim request or response evidence.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(
        #[SensitiveParameter] array $value,
        string $key,
    ): ?string {
        $result = $value[$key] ?? null;

        if ($result !== null && ! \is_string($result)) {
            throw new InvalidArgumentException("The brokered effect execution result field {$key} must be null or string.");
        }

        return $result;
    }
}
