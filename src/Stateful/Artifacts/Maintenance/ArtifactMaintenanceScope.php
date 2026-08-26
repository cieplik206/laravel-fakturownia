<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactMaintenanceScope
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $connectionKey,
        public ArtifactStorageNamespace $storageNamespace,
        public DeploymentStage $deploymentStage,
        public ArtifactStorageTopology $storageTopology,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The artifact maintenance connection scope is invalid.');
        }
    }
}
