<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use InvalidArgumentException;

final readonly class IncrementalSyncCheckpoint
{
    use RejectsNativeSerialization;

    public function __construct(
        public SyncIntegrityScope $scope,
        public ?RemoteSyncCursor $cursor,
        public int $leaseGeneration,
    ) {
        if ($leaseGeneration < 0 || $leaseGeneration > 2_147_483_647) {
            throw new InvalidArgumentException('The sync checkpoint lease generation is invalid.');
        }
    }
}
