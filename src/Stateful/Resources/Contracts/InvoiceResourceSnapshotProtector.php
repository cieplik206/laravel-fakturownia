<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources\Contracts;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\ProtectedInvoiceResourceSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

interface InvoiceResourceSnapshotProtector
{
    public function protect(InvoiceResourceProjectionPlan $plan): ProtectedInvoiceResourceSnapshot;

    public function recover(
        InvoiceResourceId $resourceId,
        ConnectionKey $connectionKey,
        OperationId $operationId,
        ProtectedInvoiceResourceSnapshot $snapshot,
    ): InvoiceResourceSnapshot;
}
