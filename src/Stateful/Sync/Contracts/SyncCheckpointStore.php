<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync\Contracts;

use Cieplik206\Fakturownia\Stateful\Sync\IncrementalSyncCheckpoint;
use Cieplik206\Fakturownia\Stateful\Sync\RemoteSyncCursor;
use Cieplik206\Fakturownia\Stateful\Sync\SyncCheckpointLease;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;

interface SyncCheckpointStore
{
    public function checkpoint(SyncIntegrityScope $scope): IncrementalSyncCheckpoint;

    public function acquire(SyncIntegrityScope $scope, int $leaseSeconds): ?SyncCheckpointLease;

    public function renew(SyncCheckpointLease $lease, int $leaseSeconds): SyncCheckpointLease;

    public function advance(SyncCheckpointLease $lease, RemoteSyncCursor $cursor): IncrementalSyncCheckpoint;

    public function release(SyncCheckpointLease $lease): void;
}
