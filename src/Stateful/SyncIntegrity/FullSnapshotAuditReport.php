<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FullSnapshotAuditReport
{
    use RejectsNativeSerialization;

    private const int MaximumSnapshotsPerSide = 10_000;

    private const int MaximumMutations = 20_000;

    /** @var list<SyncIntegrityMutation> */
    public array $mutations;

    /** @param array<mixed> $mutations */
    public function __construct(
        public SyncIntegrityScope $scope,
        public int $keyVersion,
        public int $storedCount,
        public int $observedCount,
        public int $unchangedCount,
        array $mutations,
        public DateTimeImmutable $completedAt,
    ) {
        if ($keyVersion < 1) {
            throw new InvalidArgumentException('The full snapshot audit key version must be positive.');
        }

        if ($storedCount < 0 || $observedCount < 0 || $unchangedCount < 0) {
            throw new InvalidArgumentException('The full snapshot audit counters cannot be negative.');
        }

        if ($storedCount > self::MaximumSnapshotsPerSide
            || $observedCount > self::MaximumSnapshotsPerSide
            || count($mutations) > self::MaximumMutations) {
            throw new InvalidArgumentException('The full snapshot audit exceeds its size limits.');
        }

        if ($unchangedCount > $storedCount || $unchangedCount > $observedCount) {
            throw new InvalidArgumentException('The full snapshot audit unchanged count is inconsistent.');
        }

        if ($completedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The full snapshot audit completion time must use UTC.');
        }

        $addedCount = 0;
        $changedCount = 0;
        $restoredCount = 0;
        $tombstonedCount = 0;

        foreach ($mutations as $mutation) {
            if (! $mutation instanceof SyncIntegrityMutation) {
                throw new InvalidArgumentException('The full snapshot audit contains an invalid mutation.');
            }

            if ($mutation->remoteIdentity->keyVersion !== $keyVersion) {
                throw new InvalidArgumentException('The full snapshot audit mutation uses a different HMAC key version.');
            }

            if ($mutation->detectedAt > $completedAt) {
                throw new InvalidArgumentException('The full snapshot audit mutation cannot postdate completion.');
            }

            match ($mutation->kind) {
                SyncIntegrityMutationKind::Added => $addedCount++,
                SyncIntegrityMutationKind::Changed => $changedCount++,
                SyncIntegrityMutationKind::Restored => $restoredCount++,
                SyncIntegrityMutationKind::Tombstoned => $tombstonedCount++,
            };
        }

        if ($unchangedCount + $addedCount + $changedCount + $restoredCount !== $observedCount) {
            throw new InvalidArgumentException('The full snapshot audit observed count is inconsistent.');
        }

        if ($unchangedCount + $changedCount + $restoredCount + $tombstonedCount > $storedCount) {
            throw new InvalidArgumentException('The full snapshot audit stored count is inconsistent.');
        }

        $this->mutations = array_values($mutations);
    }

    public function mutationCount(SyncIntegrityMutationKind $kind): int
    {
        return count(array_filter(
            $this->mutations,
            static fn (SyncIntegrityMutation $mutation): bool => $mutation->kind === $kind,
        ));
    }

    public function hasDrift(): bool
    {
        return $this->mutations !== [];
    }
}
