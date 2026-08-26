<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class ConsumptionReceipt
{
    public function __construct(
        #[SensitiveParameter] public ConsumptionReceiptEnvelope $envelope,
        #[SensitiveParameter] public string $signature,
    ) {
        $decoded = \base64_decode($signature, true);

        if ($decoded === false || \strlen($decoded) !== 64 || \base64_encode($decoded) !== $signature) {
            throw new InvalidArgumentException('The consumption receipt signature must be canonical base64 Ed25519 bytes.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        $keys = \array_keys($value);
        \sort($keys);

        if ($keys !== ['envelope', 'signature']
            || ! \is_array($value['envelope'] ?? null)
            || ! \is_string($value['signature'] ?? null)) {
            throw new InvalidArgumentException('The signed consumption receipt must contain only an envelope and signature.');
        }

        return new self(
            ConsumptionReceiptEnvelope::fromArray($value['envelope']),
            $value['signature'],
        );
    }

    /** @return array{envelope: array<string, mixed>, signature: string} */
    public function toArray(): array
    {
        return [
            'envelope' => $this->envelope->toArray(),
            'signature' => $this->signature,
        ];
    }
}
