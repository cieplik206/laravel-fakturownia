<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

enum ArtifactPurgePurpose: string
{
    case Orphan = 'orphan';
    case Expired = 'expired';
}
