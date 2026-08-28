<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use InvalidArgumentException;

final readonly class ChangeCostInvoiceStatusOutcomeProjector implements OutcomeProjector
{
    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if ($operation->operationType()->value !== ChangeCostInvoiceStatusOperationFactory::OperationType
            || ! $outcome->result instanceof ChangeCostInvoiceStatusResult) {
            throw new InvalidArgumentException('Cost invoice status projector received an unsupported outcome.');
        }
    }
}
