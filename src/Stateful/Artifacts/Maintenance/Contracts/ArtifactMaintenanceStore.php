<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectObservation;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeDeadline;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeOutcome;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermit;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStoreCapabilities;
use DateTimeImmutable;

interface ArtifactMaintenanceStore
{
    public function capabilities(ArtifactStorageNamespace $storageNamespace): ArtifactStoreCapabilities;

    /**
     * Lists only finalized immutable CAS objects inside the exact provider-owned namespace. A backend unable to provide reliable object age or a
     * bounded canonical-address cursor must fail instead of returning a partial best-effort manifest.
     */
    public function scanFinalized(
        ArtifactStorageNamespace $storageNamespace,
        DateTimeImmutable $notModifiedAfter,
        ?ContentAddress $after,
        int $limit,
    ): ArtifactObjectPage;

    public function inspectFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ?ArtifactObjectObservation;

    public function openFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactContentStream;

    /**
     * Conditionally removes the exact observed generation only after the caller proves that no database descriptor exists. Implementations must
     * reject a changed generation and must never accept a path, storage key, or bare content address as deletion authority. The backend request must
     * have a hard timeout no greater than the supplied deadline and must return DeadlineExceeded without deleting when that bound cannot be proven.
     * The permit must be consumed through the verifier supplied by ArtifactMaintenanceStoreFactory before resolving any object key or issuing the
     * destructive backend request.
     */
    public function purgeOrphan(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome;

    /**
     * Conditionally removes the exact observed generation for an expired provider descriptor. This is an object-store consistency boundary and is
     * never atomic with the later database tombstone. The backend request must have a hard timeout no greater than the supplied deadline and must
     * return DeadlineExceeded without deleting when that bound cannot be proven. The permit must be consumed through the verifier supplied by
     * ArtifactMaintenanceStoreFactory before resolving any object key or issuing the destructive backend request.
     */
    public function purgeExpired(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome;
}
