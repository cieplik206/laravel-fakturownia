<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use InvalidArgumentException;

final readonly class DeleteCostInvoiceOutcomeProjector implements OutcomeProjector
{
    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if ($operation->operationType()->value !== DeleteCostInvoiceOperationFactory::OperationType
            || ! $outcome->result instanceof DeleteCostInvoiceResult) {
            throw new InvalidArgumentException('Cost invoice delete projector received an unsupported outcome.');
        }
    }
}
