<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class IssueCorrectionOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof IssueCorrectionResult
            || $input->operation->operationType()->value !== IssueCorrectionOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('Issue correction projection planner received an unsupported result.');
        }

        return new ProjectionPlan(
            InvoiceResourceProjectionPlan::SchemaVersion,
            [new ProjectionMutation(
                InvoiceResourceProjectionPlan::TargetId,
                ['operation_id' => $input->operation->operationId()->value],
                null,
                [
                    'remote_id' => $input->result->remoteId,
                    'remote_number' => $input->result->number,
                    'source_invoice_id' => $input->result->sourceInvoiceId,
                    'result_type' => $input->result->resultType(),
                ],
            )],
        );
    }
}
