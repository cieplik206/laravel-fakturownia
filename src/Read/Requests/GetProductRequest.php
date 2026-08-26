<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class GetProductRequest extends JsonReadRequest
{
    public function __construct(string $productId)
    {
        $productId = RemoteIdentifier::assert($productId);

        parent::__construct(
            ReadCapability::ProductGet->value,
            ReadCapability::ProductGet,
            "/products/{$productId}.json",
            new QueryParameters,
        );
    }
}
