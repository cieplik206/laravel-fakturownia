<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ArtifactMaintenancePolicy
{
    use RejectsNativeSerialization;

    public function __construct(
        public int $retentionDays = 90,
        public int $orphanRetentionHours = 24,
        public int $batchSize = 100,
        public bool $requireSharedStorageInProduction = true,
    ) {
        if ($retentionDays < 1 || $retentionDays > 3_650) {
            throw new InvalidArgumentException('The artifact retention must be between 1 and 3650 days.');
        }

        if ($orphanRetentionHours < 1 || $orphanRetentionHours > 8_760) {
            throw new InvalidArgumentException('The orphan retention must be between 1 and 8760 hours.');
        }

        if ($batchSize < 1 || $batchSize > 1_000) {
            throw new InvalidArgumentException('The artifact maintenance batch size must be between 1 and 1000.');
        }
    }

    public function permitsAudit(
        ArtifactMaintenanceScope $scope,
        ArtifactStoreCapabilities $capabilities,
    ): bool {
        if ($scope->deploymentStage !== DeploymentStage::Production) {
            return true;
        }

        if ($this->requireSharedStorageInProduction) {
            return $scope->storageTopology === ArtifactStorageTopology::Shared
                && $capabilities->sharedVisibilityVerified;
        }

        return match ($scope->storageTopology) {
            ArtifactStorageTopology::Shared => $capabilities->sharedVisibilityVerified,
            ArtifactStorageTopology::SingleExecutionHost => $capabilities->singleExecutionHostVerified,
            ArtifactStorageTopology::Unverified => false,
        };
    }

    public function permitsRetention(
        ArtifactMaintenanceScope $scope,
        ArtifactStoreCapabilities $capabilities,
    ): bool {
        return $this->permitsAudit($scope, $capabilities)
            && $capabilities->conditionalGenerationDelete;
    }

    public function permitsOrphanSweep(
        ArtifactMaintenanceScope $scope,
        ArtifactStoreCapabilities $capabilities,
    ): bool {
        return $this->permitsRetention($scope, $capabilities)
            && $capabilities->boundedFinalizedListing
            && $capabilities->reliableObjectAge;
    }

    public function orphanCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        $this->assertUtc($now);

        return $now->modify("-{$this->orphanRetentionHours} hours");
    }

    public function expectedExpiry(DateTimeImmutable $readyAt): DateTimeImmutable
    {
        $this->assertUtc($readyAt);

        return $readyAt->modify("+{$this->retentionDays} days");
    }

    private function assertUtc(DateTimeImmutable $time): void
    {
        if ($time->getOffset() !== 0) {
            throw new InvalidArgumentException('Artifact maintenance time must use UTC.');
        }
    }
}
