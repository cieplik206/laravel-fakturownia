<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class GetProformaRequest extends JsonReadRequest
{
    public function __construct(string $proformaId)
    {
        $proformaId = RemoteIdentifier::assert($proformaId);

        parent::__construct(
            ReadCapability::InvoiceGet->value,
            ReadCapability::InvoiceGet,
            "/invoices/{$proformaId}.json",
            new QueryParameters,
        );
    }
}
