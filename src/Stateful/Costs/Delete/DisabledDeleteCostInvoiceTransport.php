<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\Fakturownia\Stateful\Costs\Delete\Contracts\DeleteCostInvoiceTransport;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledDeleteCostInvoiceTransport implements DeleteCostInvoiceTransport
{
    public function delete(
        ConnectionKey $connection,
        string $remoteId,
        EffectBoundary $boundary,
    ): DeleteCostInvoiceResult {
        throw DeleteCostInvoiceOperationFailure::capabilityUnavailable();
    }
}
