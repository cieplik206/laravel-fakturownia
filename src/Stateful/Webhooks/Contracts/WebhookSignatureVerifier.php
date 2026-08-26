<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks\Contracts;

use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookSignatureVerification;

/**
 * A verifier must be a pure, local check over the exact inbound bytes and headers.
 * It must never perform provider I/O.
 */
interface WebhookSignatureVerifier
{
    public function verify(WebhookDelivery $delivery): WebhookSignatureVerification;
}
