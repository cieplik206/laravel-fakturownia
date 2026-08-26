<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class ClaimCursor
{
    public function __construct(
        #[SensitiveParameter] public string $storeId,
        #[SensitiveParameter] public string $sequence,
    ) {
        if (\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $storeId) !== 1) {
            throw new InvalidArgumentException('The consumption claim cursor store ID is invalid.');
        }

        if (\preg_match('/^[1-9][0-9]*$/D', $sequence) !== 1) {
            throw new InvalidArgumentException('The consumption claim cursor sequence must be a canonical positive decimal string.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(#[SensitiveParameter] array $value): self
    {
        $keys = \array_keys($value);
        \sort($keys);

        if ($keys !== ['sequence', 'store_id']
            || ! \is_string($value['store_id'] ?? null)
            || ! \is_string($value['sequence'] ?? null)) {
            throw new InvalidArgumentException('The consumption claim cursor must use the exact version 1 contract.');
        }

        return new self($value['store_id'], $value['sequence']);
    }

    /** @return array{store_id: string, sequence: string} */
    public function toArray(): array
    {
        return [
            'store_id' => $this->storeId,
            'sequence' => $this->sequence,
        ];
    }

    public function key(): string
    {
        return "{$this->storeId}:{$this->sequence}";
    }
}
