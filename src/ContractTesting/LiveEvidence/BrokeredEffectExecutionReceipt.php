<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredEffectExecutionReceipt implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-effect-execution-receipt';

    public const Version = '1';

    public const Algorithm = 'Ed25519';

    private function __construct(
        public string $signerId,
        public string $issuedAt,
        public string $expiresAt,
        public LiveEffectDescriptor $descriptor,
        public string $casRecordSha256,
        public BrokeredEffectDisposition $disposition,
        public ?string $requestStartedAt,
        public ?string $responseReceivedAt,
        public int $httpStatus,
        public ?string $contentType,
        public ?string $providerRequestIdHmacSha256,
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
            'brokered effect execution receipt',
        );
        $envelope = $document['envelope'] ?? null;
        $signature = $document['signature'] ?? null;

        if (! is_array($envelope)
            || array_is_list($envelope)
            || ! is_string($signature)) {
            throw new InvalidArgumentException('The brokered effect receipt must contain one envelope and signature.');
        }

        NativeBrokerWireValidation::assertExactKeys($envelope, [
            'contract',
            'version',
            'algorithm',
            'signer_id',
            'issued_at',
            'expires_at',
            'descriptor',
            'cas_record_sha256',
            'disposition',
            'request_started_at',
            'response_received_at',
            'http_status',
            'content_type',
            'provider_request_id_hmac_sha256',
            'response_body_sha256',
            'response_size_bytes',
        ], 'brokered effect execution receipt envelope');

        if (($envelope['contract'] ?? null) !== self::Contract
            || ($envelope['version'] ?? null) !== self::Version
            || ($envelope['algorithm'] ?? null) !== self::Algorithm
            || ! is_array($envelope['descriptor'] ?? null)
            || array_is_list($envelope['descriptor'])
            || ! is_string($envelope['disposition'] ?? null)) {
            throw new InvalidArgumentException('The brokered effect receipt must use the exact version 1 contract.');
        }

        $disposition = BrokeredEffectDisposition::tryFrom($envelope['disposition']);

        if (! $disposition instanceof BrokeredEffectDisposition) {
            throw new InvalidArgumentException('The brokered effect receipt disposition is invalid.');
        }

        $receipt = new self(
            NativeBrokerWireValidation::string($envelope, 'signer_id', 'brokered effect receipt'),
            NativeBrokerWireValidation::string($envelope, 'issued_at', 'brokered effect receipt'),
            NativeBrokerWireValidation::string($envelope, 'expires_at', 'brokered effect receipt'),
            LiveEffectDescriptor::fromArray($envelope['descriptor']),
            NativeBrokerWireValidation::string($envelope, 'cas_record_sha256', 'brokered effect receipt'),
            $disposition,
            self::nullableString($envelope, 'request_started_at'),
            self::nullableString($envelope, 'response_received_at'),
            NativeBrokerWireValidation::integer($envelope, 'http_status', 'brokered effect receipt'),
            self::nullableString($envelope, 'content_type'),
            self::nullableString($envelope, 'provider_request_id_hmac_sha256'),
            NativeBrokerWireValidation::string($envelope, 'response_body_sha256', 'brokered effect receipt'),
            NativeBrokerWireValidation::integer($envelope, 'response_size_bytes', 'brokered effect receipt'),
            $signature,
        );
        $receipt->assertValid();

        return $receipt;
    }

    /** @return array<string, int|string|array<string, int|string>|null> */
    public function envelope(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'algorithm' => self::Algorithm,
            'signer_id' => $this->signerId,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'descriptor' => $this->descriptor->toArray(),
            'cas_record_sha256' => $this->casRecordSha256,
            'disposition' => $this->disposition->value,
            'request_started_at' => $this->requestStartedAt,
            'response_received_at' => $this->responseReceivedAt,
            'http_status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'provider_request_id_hmac_sha256' => $this->providerRequestIdHmacSha256,
            'response_body_sha256' => $this->responseBodySha256,
            'response_size_bytes' => $this->responseSizeBytes,
        ];
    }

    /** @return array{envelope: array<string, mixed>, signature: string} */
    public function toArray(): array
    {
        return ['envelope' => $this->envelope(), 'signature' => $this->signature];
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
        return hash('sha256', $this->canonical());
    }

    /** @return array{brokered_effect_execution_receipt: string} */
    public function __debugInfo(): array
    {
        return ['brokered_effect_execution_receipt' => '[VERIFIED_SECRET_FREE]'];
    }

    /** @return array{brokered_effect_execution_receipt: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered effect receipts cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered effect receipts cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered effect receipts cannot be unserialized.');
    }

    private function assertValid(): void
    {
        NativeBrokerWireValidation::assertIdentifier($this->signerId, 'brokered effect receipt signer');
        $issuedAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->issuedAt, 'brokered effect receipt issue time');
        $expiresAt = NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->expiresAt, 'brokered effect receipt expiry time');
        NativeBrokerWireValidation::assertBoundedValidity($issuedAt, $expiresAt, 600, 'brokered effect receipt');
        NativeBrokerWireValidation::assertSha256($this->casRecordSha256, 'brokered effect receipt CAS record');
        NativeBrokerWireValidation::assertSha256($this->responseBodySha256, 'brokered effect receipt response body');
        NativeBrokerWireValidation::assertCanonicalBase64Bytes($this->signature, 64, 'brokered effect receipt signature');

        if ($this->providerRequestIdHmacSha256 !== null) {
            NativeBrokerWireValidation::assertSha256($this->providerRequestIdHmacSha256, 'brokered effect provider request ID');
        }

        if ($this->responseSizeBytes < 0 || $this->responseSizeBytes > 1_048_576) {
            throw new InvalidArgumentException('The brokered effect receipt response size is invalid.');
        }

        if ($this->disposition === BrokeredEffectDisposition::Applied) {
            if ($this->requestStartedAt === null
                || $this->responseReceivedAt === null
                || $this->httpStatus < 100
                || $this->httpStatus > 599) {
                throw new InvalidArgumentException('An applied brokered effect receipt has no bounded response.');
            }

            self::assertTimeline($this->requestStartedAt, $this->responseReceivedAt);

            return;
        }

        if ($this->disposition === BrokeredEffectDisposition::PossiblyApplied) {
            if ($this->requestStartedAt === null
                || $this->responseReceivedAt !== null
                || $this->httpStatus !== 0
                || $this->contentType !== null
                || $this->providerRequestIdHmacSha256 !== null
                || $this->responseSizeBytes !== 0
                || ! hash_equals(hash('sha256', ''), $this->responseBodySha256)) {
                throw new InvalidArgumentException('A possibly-applied brokered effect receipt overclaims evidence.');
            }

            NativeBrokerWireValidation::strictUtcMicrosecondInstant($this->requestStartedAt, 'brokered effect receipt request time');

            return;
        }

        if ($this->requestStartedAt !== null
            || $this->responseReceivedAt !== null
            || $this->httpStatus !== 0
            || $this->contentType !== null
            || $this->providerRequestIdHmacSha256 !== null
            || $this->responseSizeBytes !== 0
            || ! hash_equals(hash('sha256', ''), $this->responseBodySha256)) {
            throw new InvalidArgumentException('A non-executed brokered effect receipt overclaims evidence.');
        }
    }

    private static function assertTimeline(string $startedAt, string $receivedAt): void
    {
        $started = NativeBrokerWireValidation::strictUtcMicrosecondInstant($startedAt, 'brokered effect receipt request time');
        $received = NativeBrokerWireValidation::strictUtcMicrosecondInstant($receivedAt, 'brokered effect receipt response time');

        if ($received < $started) {
            throw new InvalidArgumentException('The brokered effect receipt timeline is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(#[SensitiveParameter] array $value, string $key): ?string
    {
        $result = $value[$key] ?? null;

        if ($result !== null && ! is_string($result)) {
            throw new InvalidArgumentException("The brokered effect receipt field {$key} must be null or string.");
        }

        return $result;
    }
}
