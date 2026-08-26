<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookSignatureVerifier;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookSignatureVerification;

/**
 * The provider signature contract is not frozen by live evidence yet.
 * Production must therefore classify every delivery as unverified.
 */
final class DeferredWebhookSignatureVerifier implements WebhookSignatureVerifier
{
    public function verify(WebhookDelivery $delivery): WebhookSignatureVerification
    {
        return WebhookSignatureVerification::Unverified;
    }
}
