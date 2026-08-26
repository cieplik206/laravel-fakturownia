<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;

final readonly class ArtifactMaintenanceFinding
{
    use RejectsNativeSerialization;

    public function __construct(
        public ArtifactMaintenanceIssue $issue,
        public ?string $artifactId = null,
        public ?ContentAddress $contentAddress = null,
    ) {}
}
