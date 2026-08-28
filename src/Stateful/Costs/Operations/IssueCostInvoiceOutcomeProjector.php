<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\IssueCostInvoiceResourceProjectionMapper;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;

final readonly class IssueCostInvoiceOutcomeProjector implements OutcomeProjector
{
    public function __construct(
        private IssueCostInvoiceResourceProjectionMapper $mapper,
        private InvoiceResourceProjectionStore $store,
    ) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        $this->store->apply($this->mapper->map($operation, $outcome->result));
    }
}
