<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefOwnership: string
{
    case ExplicitSdk = 'explicit_sdk';
    case ProviderAutoSend = 'provider_auto_send';

    public function permitsExplicitSend(): bool
    {
        return $this === self::ExplicitSdk;
    }
}
