<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class ChangeCostInvoiceStatusResult implements OperationResult
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(string $remoteId, public OpenInvoiceStatus $status)
    {
        $this->remoteId = RemoteIdentifier::assert($remoteId);
    }

    public function resultType(): string
    {
        return 'fakturownia.cost_invoice_status_changed';
    }
}
