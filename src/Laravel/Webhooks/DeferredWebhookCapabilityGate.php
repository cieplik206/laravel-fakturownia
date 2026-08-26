<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookCapabilityDeferred;
use LogicException;

/**
 * S8.14 remains disabled until S8.1 pins matching live evidence for signature,
 * delivery ID, redelivery, acknowledgement, and ordering semantics.
 */
final class DeferredWebhookCapabilityGate
{
    public function assertActive(): void
    {
        throw new WebhookCapabilityDeferred(
            'The Fakturownia webhook receiver is deferred until the S8.1 live contract gate passes.',
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Webhook capability gates cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook capability gates cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook capability gates cannot be unserialized.');
    }
}
