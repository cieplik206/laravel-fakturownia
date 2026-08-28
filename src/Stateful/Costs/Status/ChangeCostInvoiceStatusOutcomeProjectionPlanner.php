<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class ChangeCostInvoiceStatusOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public const int SchemaVersion = 1;

    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof ChangeCostInvoiceStatusResult
            || $input->operation->operationType()->value !== ChangeCostInvoiceStatusOperationFactory::OperationType) {
            throw new InvalidArgumentException('Cost invoice status projection planner received an unsupported result.');
        }

        return new ProjectionPlan(self::SchemaVersion, []);
    }
}
