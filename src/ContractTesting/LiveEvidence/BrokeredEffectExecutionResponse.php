<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredEffectExecutionResponse implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-effect-execution-response';

    public const Version = '1';

    private function __construct(
        public LiveEffectDescriptor $descriptor,
        public BrokeredEffectExecutionResult $result,
        public BrokeredEffectExecutionReceipt $receipt,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $value,
            ['contract', 'version', 'descriptor', 'result', 'receipt'],
            'brokered effect execution response',
        );

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! \is_array($value['descriptor'] ?? null)
            || \array_is_list($value['descriptor'])
            || ! \is_array($value['result'] ?? null)
            || \array_is_list($value['result'])
            || ! \is_array($value['receipt'] ?? null)
            || \array_is_list($value['receipt'])) {
            throw new InvalidArgumentException('The brokered effect response must use the exact version 1 contract.');
        }

        $descriptor = LiveEffectDescriptor::fromArray($value['descriptor']);
        $result = BrokeredEffectExecutionResult::fromArray($value['result']);
        $receipt = BrokeredEffectExecutionReceipt::fromArray($value['receipt']);
        $response = new self($descriptor, $result, $receipt);
        $response->assertBindings();

        return $response;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'descriptor' => $this->descriptor->toArray(),
            'result' => $this->result->toArray(),
            'receipt' => $this->receipt->toArray(),
        ];
    }

    public function canonical(): string
    {
        return CanonicalCodec::encode($this->toArray());
    }

    /** @return array{brokered_effect_execution_response: string} */
    public function __debugInfo(): array
    {
        return ['brokered_effect_execution_response' => '[REDACTED]'];
    }

    /** @return array{brokered_effect_execution_response: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered effect execution responses cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered effect execution responses cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered effect execution responses cannot be unserialized.');
    }

    private function assertBindings(): void
    {
        foreach ([
            'descriptor digest' => [$this->descriptor->sha256(), $this->result->effectDescriptorSha256],
            'effect identity' => [$this->descriptor->effectId, $this->result->effectId],
            'launch manifest' => [$this->descriptor->launchManifestSha256, $this->result->launchManifestSha256],
            'authorization set' => [$this->descriptor->authorizationSetSha256, $this->result->authorizationSetSha256],
            'broker policy' => [$this->descriptor->brokerPolicySha256, $this->result->brokerPolicySha256],
            'supervisor attestation' => [$this->descriptor->supervisorAttestationSha256, $this->result->supervisorAttestationSha256],
            'receipt descriptor' => [$this->descriptor->sha256(), $this->receipt->descriptor->sha256()],
            'receipt CAS record' => [$this->result->casRecordSha256, $this->receipt->casRecordSha256],
            'receipt disposition' => [$this->result->disposition->value, $this->receipt->disposition->value],
            'receipt request time' => [$this->result->requestStartedAt ?? '', $this->receipt->requestStartedAt ?? ''],
            'receipt response time' => [$this->result->responseReceivedAt ?? '', $this->receipt->responseReceivedAt ?? ''],
            'receipt HTTP status' => [(string) $this->result->httpStatus, (string) $this->receipt->httpStatus],
            'receipt content type' => [$this->result->contentType ?? '', $this->receipt->contentType ?? ''],
            'receipt provider request ID' => [$this->result->providerRequestIdHmacSha256 ?? '', $this->receipt->providerRequestIdHmacSha256 ?? ''],
            'receipt response digest' => [$this->result->responseBodySha256, $this->receipt->responseBodySha256],
            'receipt response size' => [(string) $this->result->responseSizeBytes, (string) $this->receipt->responseSizeBytes],
        ] as $context => [$expected, $actual]) {
            if (! \hash_equals($expected, $actual)) {
                throw new InvalidArgumentException("The brokered effect response {$context} binding does not match.");
            }
        }
    }
}
