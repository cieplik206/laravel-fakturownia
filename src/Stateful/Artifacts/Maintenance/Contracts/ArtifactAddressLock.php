<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;

interface ArtifactAddressLock
{
    /**
     * The implementation must coordinate every writer, projector, and maintenance process sharing the storage namespace. It must throw when a
     * bounded acquisition cannot be completed; returning an unowned lease is forbidden.
     */
    public function acquire(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactAddressLease;
}
