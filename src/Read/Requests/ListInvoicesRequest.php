<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\InvoiceListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListInvoicesRequest extends JsonReadRequest
{
    public function __construct(InvoiceListQuery $query)
    {
        parent::__construct(
            ReadCapability::InvoiceList->value,
            ReadCapability::InvoiceList,
            '/invoices.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
