<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete\Contracts;

use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceResult;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface DeleteCostInvoiceTransport
{
    public function delete(
        ConnectionKey $connection,
        string $remoteId,
        EffectBoundary $boundary,
    ): DeleteCostInvoiceResult;
}
