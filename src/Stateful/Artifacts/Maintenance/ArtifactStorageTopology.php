<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

enum ArtifactStorageTopology: string
{
    case Shared = 'shared';
    case SingleExecutionHost = 'single_execution_host';
    case Unverified = 'unverified';
}
