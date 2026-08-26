<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

enum ArtifactMaintenanceSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';
}
