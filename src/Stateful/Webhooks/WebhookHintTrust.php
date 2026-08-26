<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks;

enum WebhookHintTrust: string
{
    case Untrusted = 'untrusted_hint';
    case SignatureVerified = 'signature_verified_hint';
}
