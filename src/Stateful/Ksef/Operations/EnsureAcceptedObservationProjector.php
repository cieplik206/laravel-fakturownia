<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateProjectionStore;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjector;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;

final readonly class EnsureAcceptedObservationProjector implements ObservationProjector
{
    public function __construct(private KsefStateProjectionStore $store) {}

    public function project(
        OperationView $operation,
        PollOutcome|AuthoritativeReconciliationOutcome $observation,
        ObservationProjectionPlan $plan,
    ): void {
        $this->store->apply($operation, $plan);
    }
}
