<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\AccountInvoiceListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListAccountInvoicesRequest extends JsonReadRequest
{
    public function __construct(AccountInvoiceListQuery $query)
    {
        parent::__construct(
            ReadCapability::AccountInvoiceList->value,
            ReadCapability::AccountInvoiceList,
            '/invoices.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
