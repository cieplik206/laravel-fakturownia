<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

enum ArtifactStatus: string
{
    case Ready = 'ready';
    case Quarantined = 'quarantined';
    case Deleted = 'deleted';
}
