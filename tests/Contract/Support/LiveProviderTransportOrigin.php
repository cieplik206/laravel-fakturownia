<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

/**
 * Implemented only by the final S0.3/S0.4 probes. Implementations must reject
 * MockClient, forAuthorizedTesting, injected transports and non-production setup.
 */
interface LiveProviderTransportOrigin
{
    public function assertRealProviderTransportOrigin(): void;
}
