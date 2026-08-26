<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SyncIntegrityMutation
{
    use RejectsNativeSerialization;

    public function __construct(
        public SyncIntegrityMutationKind $kind,
        public SnapshotHmac $remoteIdentity,
        public ?SnapshotHmac $previousSnapshot,
        public ?SnapshotHmac $currentSnapshot,
        public DateTimeImmutable $detectedAt,
    ) {
        if ($detectedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The sync integrity mutation time must use UTC.');
        }

        $this->assertShape();
    }

    private function assertShape(): void
    {
        $valid = match ($this->kind) {
            SyncIntegrityMutationKind::Added => $this->previousSnapshot === null && $this->currentSnapshot !== null,
            SyncIntegrityMutationKind::Changed,
            SyncIntegrityMutationKind::Restored => $this->previousSnapshot !== null && $this->currentSnapshot !== null,
            SyncIntegrityMutationKind::Tombstoned => $this->previousSnapshot !== null && $this->currentSnapshot === null,
        };

        if (! $valid) {
            throw new InvalidArgumentException('The sync integrity mutation does not match its kind.');
        }

        $versions = array_filter([
            $this->remoteIdentity->keyVersion,
            $this->previousSnapshot?->keyVersion,
            $this->currentSnapshot?->keyVersion,
        ], static fn (?int $version): bool => $version !== null);

        if (count(array_unique($versions)) !== 1) {
            throw new InvalidArgumentException('The sync integrity mutation HMAC versions must agree.');
        }
    }
}
