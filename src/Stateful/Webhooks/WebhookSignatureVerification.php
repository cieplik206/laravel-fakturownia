<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

enum WebhookSignatureVerification: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';

    public function trust(): WebhookHintTrust
    {
        return match ($this) {
            self::Unverified => WebhookHintTrust::Untrusted,
            self::Verified => WebhookHintTrust::SignatureVerified,
        };
    }
}
