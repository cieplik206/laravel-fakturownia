<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotAttestation;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotAttestor;
use InvalidArgumentException;

final readonly class IncrementalSyncObservation
{
    use RejectsNativeSerialization;

    public function __construct(
        public RemoteSyncCursor $cursor,
        public SnapshotAttestation $attestation,
        SnapshotAttestor $attestor,
    ) {
        if (! $attestor->matchesRemoteIdentity(
            $attestation->scope,
            $cursor->remoteId(),
            $attestation->remoteIdentity,
        )) {
            throw new InvalidArgumentException(
                'The incremental sync cursor and snapshot attestation identify different remote records.',
            );
        }
    }
}
