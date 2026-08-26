<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use InvalidArgumentException;
use LogicException;
use Stringable;

final readonly class ProviderWebhookDeliveryId implements Stringable
{
    public const MaximumBytes = 191;

    public function __construct(private string $value)
    {
        if ($value === '' || strlen($value) > self::MaximumBytes) {
            throw new InvalidArgumentException('The provider webhook delivery ID must contain between 1 and 191 bytes.');
        }

        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('The provider webhook delivery ID must be valid UTF-8 without control characters.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Provider webhook delivery IDs cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Provider webhook delivery IDs cannot be unserialized.');
    }
}
