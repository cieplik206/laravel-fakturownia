<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

enum SyncIntegrityMutationKind: string
{
    case Added = 'added';
    case Changed = 'changed';
    case Restored = 'restored';
    case Tombstoned = 'tombstoned';
}
