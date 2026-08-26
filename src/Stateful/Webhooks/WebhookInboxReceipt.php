<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class WebhookInboxReceipt
{
    public function __construct(
        public string $id,
        public WebhookHintTrust $trust,
        public bool $duplicate,
        public int $deliveryCount,
        public DateTimeImmutable $firstReceivedAt,
        public DateTimeImmutable $lastReceivedAt,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1) {
            throw new InvalidArgumentException('The webhook inbox receipt ID must be a canonical ULID.');
        }

        if ($deliveryCount < 1) {
            throw new InvalidArgumentException('The webhook delivery count must be positive.');
        }

        if ($firstReceivedAt->getOffset() !== 0 || $lastReceivedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Webhook receipt times must use UTC.');
        }

        if ($lastReceivedAt < $firstReceivedAt) {
            throw new InvalidArgumentException('The last webhook receipt time cannot precede the first.');
        }
    }

    public function requiresAuthoritativeRead(): bool
    {
        return true;
    }

    public function mayTerminalizeOperation(): bool
    {
        return false;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook inbox receipts cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook inbox receipts cannot be unserialized.');
    }
}
