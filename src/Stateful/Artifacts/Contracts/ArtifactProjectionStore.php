<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;

interface ArtifactProjectionStore
{
    public function apply(ArtifactProjectionPlan $plan): ArtifactDescriptor;
}
