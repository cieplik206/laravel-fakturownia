<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Data\ProductListQuery;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class ListProductsRequest extends JsonReadRequest
{
    public function __construct(ProductListQuery $query)
    {
        parent::__construct(
            ReadCapability::ProductList->value,
            ReadCapability::ProductList,
            '/products.json',
            new QueryParameters($query->toQuery()),
        );
    }
}
