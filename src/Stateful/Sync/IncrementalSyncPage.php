<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use InvalidArgumentException;

final readonly class IncrementalSyncPage
{
    use RejectsNativeSerialization;

    /**
     * @param  list<IncrementalSyncObservation>  $observations
     */
    public function __construct(
        public SyncIntegrityScope $scope,
        public array $observations,
        public int $inputCount,
        public int $duplicateCount,
        public ?RemoteSyncCursor $nextCursor,
    ) {
        if ($inputCount < 0
            || $inputCount > IncrementalSyncPlanner::MaximumPageSize
            || $duplicateCount < 0
            || $duplicateCount > $inputCount
            || count($observations) !== $inputCount - $duplicateCount) {
            throw new InvalidArgumentException('The incremental sync page counts are inconsistent.');
        }

        foreach ($observations as $observation) {
            if (! $observation->attestation->scope->equals($scope)) {
                throw new InvalidArgumentException('An incremental sync page cannot mix scopes.');
            }
        }
    }
}
