<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class DeleteCostInvoiceOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public const int SchemaVersion = 1;

    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof DeleteCostInvoiceResult
            || $input->operation->operationType()->value !== DeleteCostInvoiceOperationFactory::OperationType) {
            throw new InvalidArgumentException('Cost invoice delete projection planner received an unsupported result.');
        }

        return new ProjectionPlan(self::SchemaVersion, []);
    }
}
