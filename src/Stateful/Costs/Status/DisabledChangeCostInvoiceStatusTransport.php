<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\Fakturownia\Stateful\Costs\Status\Contracts\ChangeCostInvoiceStatusTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFailure;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledChangeCostInvoiceStatusTransport implements ChangeCostInvoiceStatusTransport
{
    public function change(
        ConnectionKey $connection,
        string $remoteId,
        OpenInvoiceStatus $targetStatus,
        EffectBoundary $boundary,
    ): ChangeCostInvoiceStatusResult {
        throw IssueInvoiceOperationFailure::capabilityUnavailable();
    }
}
