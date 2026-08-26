<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateProjectionStore;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use InvalidArgumentException;

final readonly class EnsureAcceptedOutcomeProjector implements OutcomeProjector
{
    public function __construct(
        private KsefStateProjectionStore $store,
    ) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if (! $outcome->result instanceof EnsureAcceptedResult) {
            throw new InvalidArgumentException('The KSeF outcome projector received an unsupported result.');
        }

        $plan = (new EnsureAcceptedOutcomeProjectionPlanner)->planResult($operation, $outcome->result);
        $this->store->apply(
            $operation,
            new ObservationProjectionPlan($plan->schemaVersion, $plan->mutations),
        );
    }
}
