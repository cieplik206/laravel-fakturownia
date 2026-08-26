<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookClock;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookInboxRepository;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookPayloadProtector;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookSignatureVerifier;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxEntry;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxReceipt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Local-only intake: signature classification, encryption, and durable inbox write.
 * It has no operation terminalizer, projector, queue, or HTTP transport dependency.
 */
final class RecordWebhookHintAction
{
    public function __construct(
        private readonly WebhookSignatureVerifier $signatureVerifier,
        private readonly WebhookPayloadProtector $payloadProtector,
        private readonly WebhookInboxRepository $repository,
        private readonly WebhookClock $clock,
        private readonly int $fallbackDeduplicationWindowSeconds = 300,
    ) {
        if ($fallbackDeduplicationWindowSeconds < 1 || $fallbackDeduplicationWindowSeconds > 86_400) {
            throw new InvalidArgumentException('The webhook fallback deduplication window must be between 1 second and 24 hours.');
        }
    }

    public function record(WebhookDelivery $delivery): WebhookInboxReceipt
    {
        $receivedAt = $this->clock->now();
        $verification = $this->signatureVerifier->verify($delivery);
        $protectedPayload = $this->payloadProtector->protect($delivery, $receivedAt);

        return $this->repository->store(
            new WebhookInboxEntry(
                (string) Str::ulid(),
                $delivery->connectionKey,
                $protectedPayload,
                $verification,
                $receivedAt,
            ),
            $this->fallbackDeduplicationWindowSeconds,
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Webhook hint recorders cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook hint recorders cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook hint recorders cannot be unserialized.');
    }
}
