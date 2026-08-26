<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;

final readonly class IssueCorrectionOutcomeProjector implements OutcomeProjector
{
    public function __construct(
        private CorrectionResourceProjectionMapper $mapper,
        private InvoiceResourceProjectionStore $store,
    ) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        $this->store->apply($this->mapper->map($operation, $outcome->result));
    }
}
