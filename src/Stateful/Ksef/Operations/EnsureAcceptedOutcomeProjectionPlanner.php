<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefStatusCategory;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class EnsureAcceptedOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof EnsureAcceptedResult
            || $input->operation->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('The KSeF outcome projection received an unsupported result.');
        }

        return $this->planResult($input->operation, $input->result);
    }

    public function planResult(OperationView $operation, EnsureAcceptedResult $result): ProjectionPlan
    {
        if ($operation->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('The KSeF outcome projection received an unsupported operation.');
        }

        $command = (new EnsureAcceptedPayloadCodec)->decode($operation->payload());
        $category = match ($result->outcome) {
            KsefTerminalOutcome::Accepted => KsefStatusCategory::Succeeded,
            KsefTerminalOutcome::Rejected => KsefStatusCategory::Rejected,
            KsefTerminalOutcome::NotApplicable => KsefStatusCategory::NotApplicable,
        };
        $mutations = (new EnsureAcceptedObservationProjectionPlanner)->mutations(
            $operation->operationId()->value,
            $command,
            [
                'remote_id' => $result->remoteId,
                'raw_status' => $result->rawStatus,
                'status_category' => $category->value,
                'government_id' => $result->governmentId,
                'provider_error_count' => 0,
                'offline' => false,
                'configuration_blocked' => false,
                'overdue' => false,
            ],
        );

        return new ProjectionPlan(EnsureAcceptedObservationProjectionPlanner::SchemaVersion, $mutations);
    }
}
