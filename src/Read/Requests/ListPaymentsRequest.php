<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\PaymentListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListPaymentsRequest extends JsonReadRequest
{
    public function __construct(PaymentListQuery $query)
    {
        parent::__construct(
            ReadCapability::PaymentList->value,
            ReadCapability::PaymentList,
            '/banking/payments.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
