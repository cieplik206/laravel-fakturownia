<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitVerifier;

interface ArtifactMaintenanceStoreFactory
{
    public function make(ArtifactPurgePermitVerifier $purgePermitVerifier): ArtifactMaintenanceStore;
}
