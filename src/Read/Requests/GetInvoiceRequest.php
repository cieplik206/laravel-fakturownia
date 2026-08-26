<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class GetInvoiceRequest extends JsonReadRequest
{
    public function __construct(string $invoiceId)
    {
        $invoiceId = RemoteIdentifier::assert($invoiceId);

        parent::__construct(
            ReadCapability::InvoiceGet->value,
            ReadCapability::InvoiceGet,
            "/invoices/{$invoiceId}.json",
            new QueryParameters,
        );
    }
}
