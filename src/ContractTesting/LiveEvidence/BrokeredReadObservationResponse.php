<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class BrokeredReadObservationResponse implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.brokered-read-observation-response';

    public const Version = '1';

    private function __construct(public BrokeredReadObservationResult $result) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $value,
            ['contract', 'version', 'result'],
            'brokered read observation response',
        );

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! \is_array($value['result'] ?? null)
            || \array_is_list($value['result'])) {
            throw new InvalidArgumentException('The brokered read response must use the exact version 1 contract.');
        }

        return new self(BrokeredReadObservationResult::fromArray($value['result']));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'result' => $this->result->toArray(),
        ];
    }

    /** @return array{brokered_read_observation_response: string} */
    public function __debugInfo(): array
    {
        return ['brokered_read_observation_response' => '[REDACTED]'];
    }

    /** @return array{brokered_read_observation_response: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Brokered read observation responses cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Brokered read observation responses cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Brokered read observation responses cannot be unserialized.');
    }
}
