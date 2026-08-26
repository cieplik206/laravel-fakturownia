<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use DateTimeImmutable;

interface ArtifactMaintenanceClock
{
    public function now(): DateTimeImmutable;
}
