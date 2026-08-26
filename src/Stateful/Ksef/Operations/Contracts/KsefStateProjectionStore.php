<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts;

use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;

interface KsefStateProjectionStore
{
    public function apply(OperationView $operation, ObservationProjectionPlan $plan): void;
}
