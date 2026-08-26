<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxReceipt;
use LogicException;

/**
 * Production entrypoint. It is intentionally not container-bound or routed and
 * remains hard-disabled until S8.1 supplies package-pinned live evidence.
 */
final readonly class ReceiveWebhookAction
{
    public function __construct(private RecordWebhookHintAction $recorder) {}

    public function execute(WebhookDelivery $delivery): WebhookInboxReceipt
    {
        (new DeferredWebhookCapabilityGate)->assertActive();

        return $this->recorder->record($delivery);
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook receivers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook receivers cannot be unserialized.');
    }
}
