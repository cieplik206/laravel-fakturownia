<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class ConcurrentBrokeredEffectExecutionResponse implements JsonSerializable
{
    public const Contract = 'cieplik206.fakturownia.concurrent-effect-execution-response';

    public const Version = '1';

    /** @param array{BrokeredEffectExecutionResponse, BrokeredEffectExecutionResponse} $responses */
    private function __construct(public array $responses) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        NativeBrokerWireValidation::assertExactKeys(
            $value,
            ['contract', 'version', 'responses'],
            'concurrent brokered effect response',
        );
        $documents = $value['responses'] ?? null;

        if (($value['contract'] ?? null) !== self::Contract
            || ($value['version'] ?? null) !== self::Version
            || ! \is_array($documents)
            || ! \array_is_list($documents)
            || \count($documents) !== 2
            || ! \is_array($documents[0] ?? null)
            || \array_is_list($documents[0])
            || ! \is_array($documents[1] ?? null)
            || \array_is_list($documents[1])) {
            throw new InvalidArgumentException('A concurrent brokered effect response requires exactly two response objects.');
        }

        return new self([
            BrokeredEffectExecutionResponse::fromArray($documents[0]),
            BrokeredEffectExecutionResponse::fromArray($documents[1]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::Contract,
            'version' => self::Version,
            'responses' => \array_map(
                static fn (BrokeredEffectExecutionResponse $response): array => $response->toArray(),
                $this->responses,
            ),
        ];
    }

    /** @return array{concurrent_brokered_effect_execution_response: string} */
    public function __debugInfo(): array
    {
        return ['concurrent_brokered_effect_execution_response' => '[REDACTED]'];
    }

    /** @return array{concurrent_brokered_effect_execution_response: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Concurrent brokered effect responses cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Concurrent brokered effect responses cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Concurrent brokered effect responses cannot be unserialized.');
    }
}
