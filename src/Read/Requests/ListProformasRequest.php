<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\ProformaListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListProformasRequest extends JsonReadRequest
{
    public function __construct(ProformaListQuery $query)
    {
        parent::__construct(
            ReadCapability::InvoiceList->value,
            ReadCapability::InvoiceList,
            '/invoices.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
