<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredReadObservationResult implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-read-observation-result';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    public const MaximumResponseBytes = 26_214_400;

    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public string $launchManifestSha256,
        public string $runNonce,
        public string $authorizationSetSha256,
        public string $authorizationBundleSha256,
        public string $probePlanSha256,
        public string $brokerPolicySha256,
        public string $supervisorAttestationSha256,
        public string $proposalSha256,
        public string $observationId,
        public BrokeredReadObservationDisposition $disposition,
        public string $requestStartedAt,
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
            'brokered read observation result',
        );
        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! \is_array($envelope)
            || \array_is_list($envelope)
            || ! \is_string($signature)) {
            throw new InvalidArgumentException('The brokered read observation result must contain one envelope and signature.');
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
            'authorization_bundle_sha256',
            'probe_plan_sha256',
            'broker_policy_sha256',
            'supervisor_attestation_sha256',
            'proposal_sha256',
            'observation_id',
            'disposition',
            'request_started_at',
            'response_received_at',
            'http_status',
            'content_type',
            'provider_request_id_hmac_sha256',
            'response_body_base64',
            'response_body_sha256',
            'response_size_bytes',
        ], 'brokered read observation result envelope');

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! \is_string($envelope['disposition'] ?? null)) {
            throw new InvalidArgumentException('The brokered read observation result must use the exact version 1 contract.');
        }

        $disposition = BrokeredReadObservationDisposition::tryFrom($envelope['disposition']);

        if (! $disposition instanceof BrokeredReadObservationDisposition) {
            throw new InvalidArgumentException('The brokered read observation disposition is invalid.');
        }

        $result = new self(
            NativeBrokerWireValidation::string($envelope, 'signer_id', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'issued_at', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'expires_at', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'launch_manifest_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'run_nonce', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'authorization_set_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'authorization_bundle_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'probe_plan_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'broker_policy_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'supervisor_attestation_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'proposal_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'observation_id', 'brokered read observation'),
            $disposition,
            NativeBrokerWireValidation::string($envelope, 'request_started_at', 'brokered read observation'),
            self::nullableString($envelope, 'response_received_at'),
            NativeBrokerWireValidation::integer($envelope, 'http_status', 'brokered read observation'),
            self::nullableString($envelope, 'content_type'),
            self::nullableString($envelope, 'provider_request_id_hmac_sha256'),
            NativeBrokerWireValidation::string($envelope, 'response_body_base64', 'brokered read observation'),
            NativeBrokerWireValidation::string($envelope, 'response_body_sha256', 'brokered read observation'),
            NativeBrokerWireValidation::integer($envelope, 'response_size_bytes', 'brokered read observation'),
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
            'authorization_bundle_sha256' => $this->authorizationBundleSha256,
            'probe_plan_sha256' => $this->probePlanSha256,
            'broker_policy_sha256' => $this->brokerPolicySha256,
            'supervisor_attestation_sha256' => $this->supervisorAttestationSha256,
            'proposal_sha256' => $this->proposalSha256,
            'observation_id' => $this->observationId,
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
        return ['envelope' => $this->envelope(), 'signature' => $this->signature];
    }

    public function canonicalEnvelope(): string
    {
        return CanonicalCodec::encode($this->envelope());
    }

    public function responseBody(): string
    {
        return NativeBrokerWireValidation::decodeCanonicalBase64(
            $this->responseBodyBase64,
            self::MaximumResponseBytes,
            'brokered read observation body',
        );
    }

    /** @return array{brokered_read_observation_result: string} */
    public function __debugInfo(): array
    {
        return ['brokered_read_observation_result' => '[REDACTED]'];
    }

    /** @return array{brokered_read_observation_result: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered read observation results cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered read observation results cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered read observation results cannot be unserialized.');
    }

    private function assertValid(): void
    {
        NativeBrokerWireValidation::assertIdentifier($this->signerId, 'brokered read signer');
        NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->issuedAt, 'brokered read issue time');
        NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->expiresAt, 'brokered read expiry time');
        NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->requestStartedAt, 'brokered read request time');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->runNonce, 32, 'brokered read run nonce');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->signature, 64, 'brokered read signature');

        foreach ([
            $this->launchManifestSha256,
            $this->authorizationSetSha256,
            $this->authorizationBundleSha256,
            $this->probePlanSha256,
            $this->brokerPolicySha256,
            $this->supervisorAttestationSha256,
            $this->proposalSha256,
            $this->responseBodySha256,
        ] as $sha256) {
            NativeBrokerWireValidation::assertSha256($sha256, 'brokered read binding');
        }

        if (\preg_match('/^[a-f0-9]{32}$/D', $this->observationId) !== 1
            || $this->responseSizeBytes < 0
            || $this->responseSizeBytes > self::MaximumResponseBytes) {
            throw new InvalidArgumentException('The brokered read observation identity or size is invalid.');
        }

        $body = $this->responseBody();

        if (\strlen($body) !== $this->responseSizeBytes
            || ! \hash_equals(\hash('sha256', $body), $this->responseBodySha256)) {
            throw new InvalidArgumentException('The brokered read response body binding is invalid.');
        }

        if ($this->disposition === BrokeredReadObservationDisposition::Observed) {
            if ($this->responseReceivedAt === null || $this->httpStatus < 100 || $this->httpStatus > 599) {
                throw new InvalidArgumentException('The brokered read observation has no bounded response.');
            }

            NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->responseReceivedAt, 'brokered read response time');

            return;
        }

        if ($this->responseReceivedAt !== null
            || $this->httpStatus !== 0
            || $body !== ''
            || $this->contentType !== null
            || $this->providerRequestIdHmacSha256 !== null) {
            throw new InvalidArgumentException('The brokered read transport failure overclaims evidence.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(#[SensitiveParameter] array $value, string $key): ?string
    {
        $result = $value[$key] ?? null;

        if ($result !== null && ! \is_string($result)) {
            throw new InvalidArgumentException("The brokered read field {$key} must be null or string.");
        }

        return $result;
    }
}
