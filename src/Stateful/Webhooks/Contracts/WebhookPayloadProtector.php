<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks\Contracts;

use Cieplik206\Fakturownia\Stateful\Webhooks\ProtectedWebhookPayload;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use DateTimeImmutable;

interface WebhookPayloadProtector
{
    public function protect(WebhookDelivery $delivery, DateTimeImmutable $receivedAt): ProtectedWebhookPayload;
}
