<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use DateTimeImmutable;
use InvalidArgumentException;

final class FullSnapshotAuditor
{
    private const int MaximumSnapshotsPerSide = 10_000;

    /**
     * @param  array<mixed>  $storedSnapshots
     * @param  array<mixed>  $observedSnapshots
     */
    public function audit(
        SyncIntegrityScope $scope,
        int $keyVersion,
        array $storedSnapshots,
        array $observedSnapshots,
        DateTimeImmutable $completedAt,
    ): FullSnapshotAuditReport {
        if ($completedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The full snapshot audit completion time must use UTC.');
        }

        if (count($storedSnapshots) > self::MaximumSnapshotsPerSide
            || count($observedSnapshots) > self::MaximumSnapshotsPerSide) {
            throw new InvalidArgumentException('The full snapshot audit accepts at most 10000 snapshots per side.');
        }

        $storedByIdentity = $this->indexStored($scope, $keyVersion, $storedSnapshots);
        $observedByIdentity = $this->indexObserved($scope, $keyVersion, $observedSnapshots);
        $mutations = [];
        $unchanged = 0;

        foreach ($observedByIdentity as $identity => $observed) {
            $stored = $storedByIdentity[$identity] ?? null;

            if ($stored === null) {
                $mutations[] = $this->mutation(SyncIntegrityMutationKind::Added, null, $observed, $completedAt);

                continue;
            }

            if ($stored->isTombstoned()) {
                $mutations[] = $this->mutation(SyncIntegrityMutationKind::Restored, $stored, $observed, $completedAt);

                continue;
            }

            if (! $stored->attestation->sameSnapshot($observed)) {
                $mutations[] = $this->mutation(SyncIntegrityMutationKind::Changed, $stored, $observed, $completedAt);

                continue;
            }

            $unchanged++;
        }

        foreach ($storedByIdentity as $identity => $stored) {
            if ($stored->isTombstoned() || isset($observedByIdentity[$identity])) {
                continue;
            }

            $mutations[] = $this->mutation(SyncIntegrityMutationKind::Tombstoned, $stored, null, $completedAt);
        }

        usort(
            $mutations,
            static fn (SyncIntegrityMutation $left, SyncIntegrityMutation $right): int => strcmp(
                $left->remoteIdentity->hex,
                $right->remoteIdentity->hex,
            ),
        );

        return new FullSnapshotAuditReport(
            scope: $scope,
            keyVersion: $keyVersion,
            storedCount: count($storedByIdentity),
            observedCount: count($observedByIdentity),
            unchangedCount: $unchanged,
            mutations: $mutations,
            completedAt: $completedAt,
        );
    }

    /**
     * @param  array<mixed>  $snapshots
     * @return array<string, StoredSnapshot>
     */
    private function indexStored(SyncIntegrityScope $scope, int $keyVersion, array $snapshots): array
    {
        $indexed = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof StoredSnapshot) {
                throw new InvalidArgumentException('The full snapshot audit received an invalid stored snapshot.');
            }

            $this->assertAttestationScope($scope, $keyVersion, $snapshot->attestation);
            $identity = $snapshot->attestation->remoteIdentity->hex;

            if (isset($indexed[$identity])) {
                throw new InvalidArgumentException('The full snapshot audit received a duplicate stored identity.');
            }

            $indexed[$identity] = $snapshot;
        }

        return $indexed;
    }

    /**
     * @param  array<mixed>  $snapshots
     * @return array<string, SnapshotAttestation>
     */
    private function indexObserved(SyncIntegrityScope $scope, int $keyVersion, array $snapshots): array
    {
        $indexed = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof SnapshotAttestation) {
                throw new InvalidArgumentException('The full snapshot audit received an invalid observed snapshot.');
            }

            $this->assertAttestationScope($scope, $keyVersion, $snapshot);
            $identity = $snapshot->remoteIdentity->hex;

            if (isset($indexed[$identity])) {
                throw new InvalidArgumentException('The full snapshot audit received a duplicate observed identity.');
            }

            $indexed[$identity] = $snapshot;
        }

        return $indexed;
    }

    private function assertAttestationScope(
        SyncIntegrityScope $scope,
        int $keyVersion,
        SnapshotAttestation $attestation,
    ): void {
        if (! $scope->equals($attestation->scope)) {
            throw new InvalidArgumentException('The full snapshot audit cannot mix scopes.');
        }

        if ($keyVersion < 1 || $attestation->keyVersion() !== $keyVersion) {
            throw new InvalidArgumentException('The full snapshot audit cannot mix HMAC key versions.');
        }
    }

    private function mutation(
        SyncIntegrityMutationKind $kind,
        ?StoredSnapshot $stored,
        ?SnapshotAttestation $observed,
        DateTimeImmutable $detectedAt,
    ): SyncIntegrityMutation {
        $attestation = $observed ?? $stored?->attestation;

        if ($attestation === null) {
            throw new InvalidArgumentException('A sync integrity mutation requires an attestation.');
        }

        return new SyncIntegrityMutation(
            kind: $kind,
            remoteIdentity: $attestation->remoteIdentity,
            previousSnapshot: $stored?->attestation->snapshot,
            currentSnapshot: $observed?->snapshot,
            detectedAt: $detectedAt,
        );
    }
}
