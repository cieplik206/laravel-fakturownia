<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status\Contracts;

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusResult;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface ChangeCostInvoiceStatusTransport
{
    public function change(
        ConnectionKey $connection,
        string $remoteId,
        OpenInvoiceStatus $targetStatus,
        EffectBoundary $boundary,
    ): ChangeCostInvoiceStatusResult;
}
