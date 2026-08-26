<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions;

use RuntimeException;

final class ArtifactMaintenanceBlocked extends RuntimeException
{
    public static function sharedStorageUnverified(): self
    {
        return new self('Artifact maintenance is blocked until shared storage or a single execution host is verified.');
    }

    public static function capabilitiesIncomplete(): self
    {
        return new self('Artifact maintenance is blocked because the storage cannot prove every required maintenance capability.');
    }
}
