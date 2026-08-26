<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecordPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use DateTimeImmutable;

interface ArtifactMaintenanceRepository
{
    public function auditPage(
        ArtifactMaintenanceScope $scope,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage;

    public function expiredPage(
        ArtifactMaintenanceScope $scope,
        DateTimeImmutable $now,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage;

    /**
     * Checks every connection in the exact storage namespace for a non-deleted descriptor.
     *
     * @phpstan-impure
     */
    public function hasAnyActiveReference(
        ArtifactMaintenanceScope $scope,
        ContentAddress $contentAddress,
    ): bool;

    /**
     * Checks every connection in the exact storage namespace for another non-deleted descriptor.
     *
     * @phpstan-impure
     */
    public function hasOtherActiveReference(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool;

    public function quarantine(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool;

    public function tombstone(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
        DateTimeImmutable $deletedAt,
    ): bool;
}
