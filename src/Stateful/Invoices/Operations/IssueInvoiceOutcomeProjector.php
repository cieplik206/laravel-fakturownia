<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;

final readonly class IssueInvoiceOutcomeProjector implements OutcomeProjector
{
    public function __construct(
        private IssueInvoiceResourceProjectionMapper $mapper,
        private InvoiceResourceProjectionStore $store,
    ) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        $this->store->apply($this->mapper->map($operation, $outcome->result));
    }
}
