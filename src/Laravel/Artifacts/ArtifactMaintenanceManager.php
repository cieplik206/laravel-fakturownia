<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactIntegrityVerifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceFinding;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceIssue;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenancePolicy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceReport;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectObservation;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeDeadline;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeOutcome;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitIssuer;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitVerifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStorageTopology;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStoreCapabilities;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceClock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceRepository;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions\ArtifactMaintenanceBlocked;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\SystemArtifactMaintenanceClock;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class ArtifactMaintenanceManager
{
    private const int LEASE_SAFETY_SECONDS = 5;

    private const int MAXIMUM_DOCTOR_PAGES = 10_000;

    private readonly ArtifactIntegrityVerifier $verifier;

    private function __construct(
        private readonly ArtifactMaintenanceScope $scope,
        private readonly ArtifactMaintenanceRepository $repository,
        private readonly ArtifactMaintenanceStore $store,
        private readonly ArtifactAddressLock $addressLock,
        private readonly ArtifactMaintenancePolicy $policy,
        private readonly ArtifactMaintenanceClock $clock,
        private readonly ArtifactPurgePermitIssuer $purgePermitIssuer,
        private readonly ArtifactPurgePermitVerifier $purgePermitVerifier,
    ) {
        $this->verifier = new ArtifactIntegrityVerifier($store);
    }

    /** @internal Use PostgresArtifactMaintenanceManagerFactory as the production entry point. */
    public static function fromLaravelConfiguration(
        DatabaseManager $databases,
        ConfigRepository $configuration,
        ArtifactMaintenanceStoreFactory $storeFactory,
    ): self {
        $policy = new ArtifactMaintenancePolicy(
            self::configurationInt($configuration, 'fakturownia.artifacts.retention_days'),
            self::configurationInt($configuration, 'fakturownia.artifacts.orphan_retention_hours'),
            self::configurationInt($configuration, 'fakturownia.artifacts.maintenance_batch_size'),
            self::configurationBool($configuration, 'fakturownia.artifacts.require_shared_storage_in_production'),
        );
        $scope = self::configuredScope($configuration, $policy);
        $connectionName = self::configurationString(
            $configuration,
            'integration-operations.database.connection',
        );
        $connection = $databases->connection($connectionName);

        $databaseName = $connection->getDatabaseName();
        $host = $connection->getConfig('host');
        $port = $connection->getConfig('port');

        if ($databaseName === ''
            || ! is_string($host) || $host === ''
            || ! (is_int($port) || (is_string($port) && ctype_digit($port)))) {
            throw new RuntimeException('Artifact maintenance requires one explicit PostgreSQL writer endpoint.');
        }

        $database = AttestedPostgresArtifactDatabase::attest(
            $connection,
            new SharedDatabaseArtifactLockConfiguration(
                $connectionName,
                $databaseName,
                $host,
                (int) $port,
                self::configurationString($configuration, 'fakturownia.artifacts.database_schema'),
                self::configurationString($configuration, 'fakturownia.artifacts.lock_schema'),
                'fakturownia_artifacts',
                'fakturownia_artifact_locks',
            ),
        );
        $issuer = ArtifactPurgePermitIssuer::create();
        $verifier = $issuer->verifier();

        return new self(
            $scope,
            $database->repository(),
            $storeFactory->make($verifier),
            $database->addressLock(),
            $policy,
            new SystemArtifactMaintenanceClock,
            $issuer,
            $verifier,
        );
    }

    public function doctor(): ArtifactMaintenanceReport
    {
        if (func_num_args() !== 0) {
            throw new InvalidArgumentException('Artifact maintenance scope is factory-owned and cannot be supplied per call.');
        }

        $scope = $this->scope;
        $now = $this->now();
        $capabilities = $this->store->capabilities($scope->storageNamespace);

        if (! $this->policy->permitsAudit($scope, $capabilities)) {
            return new ArtifactMaintenanceReport(0, 0, 0, 0, [
                new ArtifactMaintenanceFinding(ArtifactMaintenanceIssue::SharedStorageUnverified),
            ]);
        }

        $findings = [];
        $totalFindings = 0;
        $examined = 0;
        $artifactCursor = null;
        $artifactPages = 0;

        do {
            $page = $this->repository->auditPage($scope, $artifactCursor, $this->policy->batchSize);
            $examined += count($page->records);

            foreach ($page->records as $record) {
                if ($record->expiresAt === null
                    || $record->expiresAt != $this->policy->expectedExpiry($record->readyAt)) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(ArtifactMaintenanceIssue::RetentionPolicyMismatch, $record),
                    );
                }

                $verification = $this->verifier->inspect($scope, $record);

                if ($verification->issue !== null) {
                    $this->appendFinding($findings, $totalFindings, $this->finding($verification->issue, $record));
                }
            }

            $artifactCursor = $this->advanceArtifactCursor(
                $artifactCursor,
                $page->nextArtifactId,
                ++$artifactPages,
            );
        } while ($artifactCursor !== null);

        if ($this->policy->permitsOrphanSweep($scope, $capabilities)) {
            $objectCursor = null;
            $objectPages = 0;
            $orphanCutoff = $this->policy->orphanCutoff($now);

            do {
                $page = $this->store->scanFinalized(
                    $scope->storageNamespace,
                    $orphanCutoff,
                    $objectCursor,
                    $this->policy->batchSize,
                );

                foreach ($page->objects as $observation) {
                    if ($observation->lastModifiedAt > $orphanCutoff) {
                        $this->appendFinding(
                            $findings,
                            $totalFindings,
                            new ArtifactMaintenanceFinding(
                                ArtifactMaintenanceIssue::FreshObjectInOrphanScan,
                                contentAddress: $observation->object->contentAddress,
                            ),
                        );

                        continue;
                    }

                    if (! hash_equals($scope->storageNamespace->disk, $observation->object->disk)) {
                        $this->appendFinding(
                            $findings,
                            $totalFindings,
                            new ArtifactMaintenanceFinding(
                                ArtifactMaintenanceIssue::MetadataMismatch,
                                contentAddress: $observation->object->contentAddress,
                            ),
                        );

                        continue;
                    }

                    if (! $this->repository->hasAnyActiveReference($scope, $observation->object->contentAddress)) {
                        $this->appendFinding(
                            $findings,
                            $totalFindings,
                            new ArtifactMaintenanceFinding(
                                ArtifactMaintenanceIssue::OrphanBacklog,
                                contentAddress: $observation->object->contentAddress,
                            ),
                        );
                    }
                }

                $objectCursor = $this->advanceObjectCursor(
                    $objectCursor,
                    $page->nextAddress,
                    ++$objectPages,
                );
            } while ($objectCursor !== null);
        } else {
            $this->appendFinding(
                $findings,
                $totalFindings,
                new ArtifactMaintenanceFinding(ArtifactMaintenanceIssue::MaintenanceCapabilitiesIncomplete),
            );
        }

        return new ArtifactMaintenanceReport($examined, 0, 0, 0, $findings, totalFindings: $totalFindings);
    }

    public function prune(
        ?string $afterArtifactId = null,
    ): ArtifactMaintenanceReport {
        $this->assertArtifactCursor($afterArtifactId);
        $scope = $this->scope;
        $now = $this->now();
        $capabilities = $this->store->capabilities($scope->storageNamespace);

        if (! $this->policy->permitsAudit($scope, $capabilities)) {
            throw ArtifactMaintenanceBlocked::sharedStorageUnverified();
        }

        if (! $this->policy->permitsRetention($scope, $capabilities)) {
            throw ArtifactMaintenanceBlocked::capabilitiesIncomplete();
        }

        $page = $this->repository->auditPage($scope, $afterArtifactId, $this->policy->batchSize);
        $findings = [];
        $totalFindings = 0;
        $deleted = 0;
        $tombstoned = 0;
        $quarantined = 0;

        foreach ($page->records as $record) {
            $expectedExpiry = $this->policy->expectedExpiry($record->readyAt);

            if (! $record->belongsTo($scope)
                || $record->expiresAt === null
                || $record->expiresAt != $expectedExpiry) {
                $this->appendFinding(
                    $findings,
                    $totalFindings,
                    $this->finding(ArtifactMaintenanceIssue::RetentionPolicyMismatch, $record),
                );

                continue;
            }

            if ($expectedExpiry > $now) {
                continue;
            }

            $lease = $this->addressLock->acquire(
                $scope->storageNamespace,
                $record->object->contentAddress,
            );

            try {
                $wasReady = $record->status === ArtifactStatus::Ready;

                if (! $this->repository->quarantine($scope, $record)) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(ArtifactMaintenanceIssue::LifecycleWriteFailed, $record),
                    );

                    continue;
                }

                if ($wasReady) {
                    $quarantined++;
                }

                $claimed = new ArtifactMaintenanceRecord(
                    $record->id,
                    $record->connectionKey,
                    $record->storageNamespace,
                    $record->object,
                    ArtifactStatus::Quarantined,
                    $record->readyAt,
                    $record->expiresAt,
                );

                if ($this->repository->hasOtherActiveReference($scope, $claimed)) {
                    if ($this->repository->tombstone($scope, $claimed, $now)) {
                        $tombstoned++;
                    } else {
                        $this->appendFinding(
                            $findings,
                            $totalFindings,
                            $this->finding(ArtifactMaintenanceIssue::LifecycleWriteFailed, $claimed),
                        );
                    }

                    continue;
                }

                $verification = $this->verifier->inspect($scope, $claimed);

                if (! $verification->passes()) {
                    if (! $wasReady && $verification->issue === ArtifactMaintenanceIssue::MissingObject) {
                        if ($this->repository->tombstone($scope, $claimed, $now)) {
                            $tombstoned++;
                        } else {
                            $this->appendFinding(
                                $findings,
                                $totalFindings,
                                $this->finding(ArtifactMaintenanceIssue::LifecycleWriteFailed, $claimed),
                            );
                        }

                        continue;
                    }

                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(
                            $verification->issue ?? ArtifactMaintenanceIssue::ObjectUnreadable,
                            $claimed,
                        ),
                    );

                    continue;
                }

                $observation = $verification->observation;

                if ($observation === null) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(ArtifactMaintenanceIssue::ObjectUnreadable, $claimed),
                    );

                    continue;
                }

                $lease->assertOwned();

                if ($this->repository->hasOtherActiveReference($scope, $claimed)) {
                    if ($this->repository->tombstone($scope, $claimed, $now)) {
                        $tombstoned++;
                    } else {
                        $this->appendFinding(
                            $findings,
                            $totalFindings,
                            $this->finding(ArtifactMaintenanceIssue::LifecycleWriteFailed, $claimed),
                        );
                    }

                    continue;
                }

                $deadline = $this->purgeDeadline($capabilities);
                $lease->renewFor($deadline->maximumDurationSeconds + self::LEASE_SAFETY_SECONDS);
                $permit = $this->purgePermitIssuer->issueExpired(
                    $scope->storageNamespace,
                    $claimed,
                    $observation,
                    $deadline,
                );

                $outcome = $this->store->purgeExpired(
                    $permit,
                    $scope->storageNamespace,
                    $claimed,
                    $observation,
                    $deadline,
                );
                $this->purgePermitVerifier->assertConsumed($permit);
                $lease->assertOwned();

                if ($outcome !== ArtifactPurgeOutcome::Deleted) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(
                            match ($outcome) {
                                ArtifactPurgeOutcome::RejectedChanged => ArtifactMaintenanceIssue::ObjectChangedBeforeDelete,
                                ArtifactPurgeOutcome::DeadlineExceeded => ArtifactMaintenanceIssue::ObjectDeleteFailed,
                                default => ArtifactMaintenanceIssue::MissingObject,
                            },
                            $claimed,
                        ),
                    );

                    continue;
                }

                $deleted++;

                if ($this->repository->tombstone($scope, $claimed, $now)) {
                    $tombstoned++;
                } else {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        $this->finding(ArtifactMaintenanceIssue::LifecycleWriteFailed, $claimed),
                    );
                }
            } finally {
                $lease->release();
            }
        }

        return new ArtifactMaintenanceReport(
            count($page->records),
            $deleted,
            $tombstoned,
            $quarantined,
            $findings,
            $page->nextArtifactId,
            totalFindings: $totalFindings,
        );
    }

    public function sweep(
        ?ContentAddress $afterObjectAddress = null,
    ): ArtifactMaintenanceReport {
        $scope = $this->scope;
        $now = $this->now();
        $capabilities = $this->store->capabilities($scope->storageNamespace);

        if (! $this->policy->permitsAudit($scope, $capabilities)) {
            throw ArtifactMaintenanceBlocked::sharedStorageUnverified();
        }

        if (! $this->policy->permitsOrphanSweep($scope, $capabilities)) {
            throw ArtifactMaintenanceBlocked::capabilitiesIncomplete();
        }

        $cutoff = $this->policy->orphanCutoff($now);
        $page = $this->store->scanFinalized(
            $scope->storageNamespace,
            $cutoff,
            $afterObjectAddress,
            $this->policy->batchSize,
        );
        $findings = [];
        $totalFindings = 0;
        $deleted = 0;

        foreach ($page->objects as $observation) {
            $address = $observation->object->contentAddress;

            if ($observation->lastModifiedAt > $cutoff) {
                $this->appendFinding(
                    $findings,
                    $totalFindings,
                    new ArtifactMaintenanceFinding(
                        ArtifactMaintenanceIssue::FreshObjectInOrphanScan,
                        contentAddress: $address,
                    ),
                );

                continue;
            }

            if (! hash_equals($scope->storageNamespace->disk, $observation->object->disk)) {
                $this->appendFinding(
                    $findings,
                    $totalFindings,
                    new ArtifactMaintenanceFinding(
                        ArtifactMaintenanceIssue::MetadataMismatch,
                        contentAddress: $address,
                    ),
                );

                continue;
            }

            if ($this->repository->hasAnyActiveReference($scope, $address)) {
                continue;
            }

            $lease = $this->addressLock->acquire($scope->storageNamespace, $address);

            try {
                if ($this->repository->hasAnyActiveReference($scope, $address)) {
                    continue;
                }

                $current = $this->store->inspectFinalized($scope->storageNamespace, $address);

                if ($current === null) {
                    continue;
                }

                if (! $this->sameObservation($observation, $current)) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        new ArtifactMaintenanceFinding(
                            ArtifactMaintenanceIssue::ObjectChangedBeforeDelete,
                            contentAddress: $address,
                        ),
                    );

                    continue;
                }

                $lease->assertOwned();

                if ($this->repository->hasAnyActiveReference($scope, $address)) {
                    continue;
                }

                $deadline = $this->purgeDeadline($capabilities);
                $lease->renewFor($deadline->maximumDurationSeconds + self::LEASE_SAFETY_SECONDS);
                $permit = $this->purgePermitIssuer->issueOrphan(
                    $scope->storageNamespace,
                    $current,
                    $deadline,
                );

                $outcome = $this->store->purgeOrphan(
                    $permit,
                    $scope->storageNamespace,
                    $current,
                    $deadline,
                );
                $this->purgePermitVerifier->assertConsumed($permit);
                $lease->assertOwned();

                if ($outcome === ArtifactPurgeOutcome::Deleted) {
                    $deleted++;
                } elseif ($outcome === ArtifactPurgeOutcome::RejectedChanged) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        new ArtifactMaintenanceFinding(
                            ArtifactMaintenanceIssue::ObjectChangedBeforeDelete,
                            contentAddress: $address,
                        ),
                    );
                } elseif ($outcome === ArtifactPurgeOutcome::DeadlineExceeded) {
                    $this->appendFinding(
                        $findings,
                        $totalFindings,
                        new ArtifactMaintenanceFinding(
                            ArtifactMaintenanceIssue::ObjectDeleteFailed,
                            contentAddress: $address,
                        ),
                    );
                }
            } finally {
                $lease->release();
            }
        }

        return new ArtifactMaintenanceReport(
            count($page->objects),
            $deleted,
            0,
            0,
            $findings,
            nextObjectAddress: $page->nextAddress,
            totalFindings: $totalFindings,
        );
    }

    /** @return array{artifact_maintenance_manager: string} */
    public function __debugInfo(): array
    {
        return ['artifact_maintenance_manager' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Artifact maintenance managers cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Artifact maintenance managers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Artifact maintenance managers cannot be unserialized.');
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        if ($now->getOffset() !== 0) {
            throw new RuntimeException('The artifact maintenance clock must return UTC.');
        }

        return $now;
    }

    private function purgeDeadline(ArtifactStoreCapabilities $capabilities): ArtifactPurgeDeadline
    {
        return new ArtifactPurgeDeadline($this->now(), $capabilities->conditionalDeleteTimeoutSeconds);
    }

    private function finding(
        ArtifactMaintenanceIssue $issue,
        ArtifactMaintenanceRecord $record,
    ): ArtifactMaintenanceFinding {
        return new ArtifactMaintenanceFinding($issue, $record->id, $record->object->contentAddress);
    }

    /** @param list<ArtifactMaintenanceFinding> $findings */
    private function appendFinding(
        array &$findings,
        int &$totalFindings,
        ArtifactMaintenanceFinding $finding,
    ): void {
        $totalFindings++;

        if (count($findings) < ArtifactMaintenanceReport::MAXIMUM_FINDING_SAMPLE) {
            $findings[] = $finding;
        }
    }

    private function assertArtifactCursor(?string $cursor): void
    {
        if ($cursor !== null && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('The artifact maintenance cursor must be a canonical ULID.');
        }
    }

    private function sameObservation(
        ArtifactObjectObservation $expected,
        ArtifactObjectObservation $actual,
    ): bool {
        return hash_equals($expected->generationFingerprintSha256, $actual->generationFingerprintSha256)
            && $expected->lastModifiedAt == $actual->lastModifiedAt
            && hash_equals($expected->object->disk, $actual->object->disk)
            && hash_equals((string) $expected->object->contentAddress, (string) $actual->object->contentAddress)
            && hash_equals($expected->object->mimeType, $actual->object->mimeType)
            && $expected->object->sizeBytes === $actual->object->sizeBytes;
    }

    private function advanceArtifactCursor(
        ?string $current,
        ?string $next,
        int $pages,
    ): ?string {
        if ($pages > self::MAXIMUM_DOCTOR_PAGES || ($next !== null && $next === $current)) {
            throw new RuntimeException('The artifact doctor database scan did not complete with a progressing bounded cursor.');
        }

        return $next;
    }

    private function advanceObjectCursor(
        ?ContentAddress $current,
        ?ContentAddress $next,
        int $pages,
    ): ?ContentAddress {
        if ($pages > self::MAXIMUM_DOCTOR_PAGES
            || ($next !== null && $current !== null && hash_equals((string) $next, (string) $current))) {
            throw new RuntimeException('The artifact doctor object scan did not complete with a progressing bounded cursor.');
        }

        return $next;
    }

    private static function configurationString(
        ConfigRepository $configuration,
        string $key,
    ): string {
        $value = $configuration->get($key);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Artifact maintenance configuration [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    private static function configurationInt(
        ConfigRepository $configuration,
        string $key,
    ): int {
        $value = $configuration->get($key);

        if (! is_int($value)) {
            throw new InvalidArgumentException("Artifact maintenance configuration [{$key}] must be an integer.");
        }

        return $value;
    }

    private static function configurationBool(
        ConfigRepository $configuration,
        string $key,
    ): bool {
        $value = $configuration->get($key);

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Artifact maintenance configuration [{$key}] must be a boolean.");
        }

        return $value;
    }

    private static function configuredScope(
        ConfigRepository $configuration,
        ArtifactMaintenancePolicy $policy,
    ): ArtifactMaintenanceScope {
        $connectionKey = self::configurationString($configuration, 'fakturownia.artifacts.connection');
        $connections = $configuration->get('fakturownia.connections');
        $connection = is_array($connections) ? ($connections[$connectionKey] ?? null) : null;
        $deploymentStage = is_array($connection) ? ($connection['deployment_stage'] ?? null) : null;

        if (! is_string($deploymentStage)) {
            throw new InvalidArgumentException('The artifact maintenance connection must name one configured Fakturownia profile.');
        }

        $resolvedDeploymentStage = DeploymentStage::tryFrom($deploymentStage);

        if ($resolvedDeploymentStage === null) {
            throw new InvalidArgumentException('The artifact maintenance deployment stage is unsupported.');
        }

        $storageTopology = ArtifactStorageTopology::tryFrom(
            self::configurationString($configuration, 'fakturownia.artifacts.storage_topology'),
        );

        if ($storageTopology === null) {
            throw new InvalidArgumentException('The artifact maintenance storage topology is unsupported.');
        }

        if ($resolvedDeploymentStage === DeploymentStage::Production
            && (($policy->requireSharedStorageInProduction && $storageTopology !== ArtifactStorageTopology::Shared)
                || (! $policy->requireSharedStorageInProduction && $storageTopology === ArtifactStorageTopology::Unverified))) {
            throw new InvalidArgumentException('The production artifact maintenance topology lacks the required explicit attestation mode.');
        }

        return new ArtifactMaintenanceScope(
            $connectionKey,
            new ArtifactStorageNamespace(
                self::configurationString($configuration, 'fakturownia.artifacts.disk'),
                self::configurationString($configuration, 'fakturownia.artifacts.prefix'),
            ),
            $resolvedDeploymentStage,
            $storageTopology,
        );
    }
}
