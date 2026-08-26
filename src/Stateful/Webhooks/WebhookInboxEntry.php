<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class WebhookInboxEntry
{
    public function __construct(
        public string $id,
        public string $connectionKey,
        public ProtectedWebhookPayload $payload,
        public WebhookSignatureVerification $signatureVerification,
        public DateTimeImmutable $receivedAt,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1) {
            throw new InvalidArgumentException('The webhook receipt identifier must be a canonical ULID.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The webhook receipt connection key is invalid.');
        }

        if ($receivedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The webhook receipt time must use UTC.');
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook inbox entries cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook inbox entries cannot be unserialized.');
    }
}
