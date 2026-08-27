<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;

final readonly class IssueProformaResourceProjectionMapper
{
    private IssueInvoiceResourceProjectionMapper $mapper;

    public function __construct(HmacSha256 $hmac)
    {
        $this->mapper = new IssueInvoiceResourceProjectionMapper($hmac);
    }

    public function map(OperationView $operation, OperationResult $result): InvoiceResourceProjectionPlan
    {
        return $this->mapper->map($operation, $result);
    }
}
