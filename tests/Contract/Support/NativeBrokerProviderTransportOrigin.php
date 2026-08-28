<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Tests\Contract\Support;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerSession;

interface NativeBrokerProviderTransportOrigin extends LiveProviderTransportOrigin
{
    public function nativeBrokerSession(): NativeBrokerSession;
}
