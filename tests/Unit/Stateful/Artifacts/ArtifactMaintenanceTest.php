<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\ArtifactMaintenanceManager;
use Cieplik206\Fakturownia\Laravel\Artifacts\CacheArtifactAddressLock;
use Cieplik206\Fakturownia\Laravel\Artifacts\PostgresArtifactMaintenanceManagerFactory;
use Cieplik206\Fakturownia\Laravel\Artifacts\SharedDatabaseArtifactLockConfiguration;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceIssue;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenancePolicy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecord;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceRecordPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceReport;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectObservation;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactObjectPage;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeDeadline;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgeOutcome;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermit;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitIssuer;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitVerifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStorageTopology;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStoreCapabilities;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLease;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceClock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceRepository;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions\ArtifactMaintenanceBlocked;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions\ArtifactPurgeUnauthorized;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\Ulid;

final class S64MemoryArtifactStream extends ArtifactContentStream
{
    private int $offset = 0;

    private bool $closed = false;

    public function __construct(private readonly string $contents) {}

    public function read(int $length): string
    {
        if ($this->closed || $length < 1) {
            throw new LogicException('The test artifact stream cannot be read.');
        }

        $chunk = substr($this->contents, $this->offset, $length);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class S64OperationLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class S64FrozenArtifactClock implements ArtifactMaintenanceClock
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class S64FakeArtifactStore implements ArtifactMaintenanceStore
{
    public int $capabilityChecks = 0;

    public int $scanChecks = 0;

    /** @var array<string, ArtifactObjectObservation> */
    public array $observations = [];

    /** @var array<string, string> */
    public array $contents = [];

    /** @var list<string> */
    public array $purgedOrphans = [];

    /** @var list<string> */
    public array $purgedExpired = [];

    public ?ArtifactPurgeOutcome $forcedPurgeOutcome = null;

    public bool $includeFreshInScan = false;

    public int $advanceClockDuringPurgeSeconds = 0;

    public ?ArtifactAddressLock $concurrentWriterLock = null;

    public ?ArtifactAddressLease $concurrentWriterLease = null;

    public readonly ArtifactPurgePermitIssuer $purgePermitIssuer;

    public readonly ArtifactPurgePermitVerifier $purgePermitVerifier;

    public ?ArtifactPurgePermit $lastPermit = null;

    public function __construct(
        private readonly ArtifactStoreCapabilities $storeCapabilities,
        private readonly S64OperationLog $log,
    ) {
        $this->purgePermitIssuer = ArtifactPurgePermitIssuer::create();
        $this->purgePermitVerifier = $this->purgePermitIssuer->verifier();
    }

    public function capabilities(ArtifactStorageNamespace $storageNamespace): ArtifactStoreCapabilities
    {
        $this->capabilityChecks++;

        return $this->storeCapabilities;
    }

    public function scanFinalized(
        ArtifactStorageNamespace $storageNamespace,
        DateTimeImmutable $notModifiedAfter,
        ?ContentAddress $after,
        int $limit,
    ): ArtifactObjectPage {
        $this->scanChecks++;
        $objects = array_values(array_filter(
            $this->observations,
            fn (ArtifactObjectObservation $observation): bool => ($this->includeFreshInScan || $observation->lastModifiedAt <= $notModifiedAfter)
                && ($after === null || (string) $observation->object->contentAddress > (string) $after),
        ));

        usort(
            $objects,
            fn (ArtifactObjectObservation $left, ArtifactObjectObservation $right): int => (string) $left->object->contentAddress <=> (string) $right->object->contentAddress,
        );
        $hasMore = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);
        $last = $objects === [] ? null : $objects[array_key_last($objects)];

        return new ArtifactObjectPage(
            $objects,
            $hasMore && $last instanceof ArtifactObjectObservation ? $last->object->contentAddress : null,
        );
    }

    public function inspectFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ?ArtifactObjectObservation {
        return $this->observations[(string) $contentAddress] ?? null;
    }

    public function openFinalized(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactContentStream {
        $contents = $this->contents[(string) $contentAddress] ?? null;

        if (! is_string($contents)) {
            throw new RuntimeException('The fake artifact object is absent.');
        }

        return new S64MemoryArtifactStream($contents);
    }

    public function purgeOrphan(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome {
        $this->purgePermitVerifier->consumeOrphan($permit, $storageNamespace, $observation, $deadline);
        $this->lastPermit = $permit;
        $address = (string) $observation->object->contentAddress;
        $outcome = $this->purgeOutcome($storageNamespace, $observation, $deadline);

        if ($outcome === ArtifactPurgeOutcome::Deleted) {
            $this->purgedOrphans[] = $address;
        }

        return $outcome;
    }

    public function purgeExpired(
        ArtifactPurgePermit $permit,
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome {
        $this->purgePermitVerifier->consumeExpired($permit, $storageNamespace, $record, $observation, $deadline);
        $this->lastPermit = $permit;
        $address = (string) $record->object->contentAddress;
        $this->log->entries[] = 'purge:'.$record->id;
        $outcome = $this->purgeOutcome($storageNamespace, $observation, $deadline);

        if ($outcome === ArtifactPurgeOutcome::Deleted) {
            $this->purgedExpired[] = $address;
        }

        return $outcome;
    }

    private function purgeOutcome(
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): ArtifactPurgeOutcome {
        if ($this->forcedPurgeOutcome !== null) {
            return $this->forcedPurgeOutcome;
        }

        if ($this->advanceClockDuringPurgeSeconds > 0) {
            Carbon::setTestNow(Carbon::now('UTC')->addSeconds($this->advanceClockDuringPurgeSeconds));

            if ($this->concurrentWriterLock !== null) {
                $this->concurrentWriterLease = $this->concurrentWriterLock->acquire(
                    $storageNamespace,
                    $observation->object->contentAddress,
                );
            }

            $currentTime = DateTimeImmutable::createFromInterface(Carbon::now('UTC'));

            if ($deadline->hasExpiredAt($currentTime)) {
                return ArtifactPurgeOutcome::DeadlineExceeded;
            }
        }

        $address = (string) $observation->object->contentAddress;
        $current = $this->observations[$address] ?? null;

        if ($current === null) {
            return ArtifactPurgeOutcome::AlreadyAbsent;
        }

        if (! hash_equals($observation->generationFingerprintSha256, $current->generationFingerprintSha256)) {
            return ArtifactPurgeOutcome::RejectedChanged;
        }

        unset($this->observations[$address], $this->contents[$address]);

        return ArtifactPurgeOutcome::Deleted;
    }
}

final class S64FakeArtifactRepository implements ArtifactMaintenanceRepository
{
    public int $auditChecks = 0;

    /** @var array<string, ArtifactMaintenanceRecord> */
    public array $records = [];

    /** @var array<string, true> */
    public array $terminalAddresses = [];

    /** @var list<string> */
    public array $quarantined = [];

    /** @var list<string> */
    public array $tombstoned = [];

    public bool $quarantineFails = false;

    public bool $tombstoneFails = false;

    public ?int $descriptorAppearsOnCheck = null;

    public bool $repeatArtifactCursor = false;

    private int $descriptorChecks = 0;

    public function __construct(private readonly S64OperationLog $log) {}

    public function auditPage(
        ArtifactMaintenanceScope $scope,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage {
        $this->auditChecks++;

        return $this->page($scope, $afterArtifactId, $limit, false, null);
    }

    public function expiredPage(
        ArtifactMaintenanceScope $scope,
        DateTimeImmutable $now,
        ?string $afterArtifactId,
        int $limit,
    ): ArtifactMaintenanceRecordPage {
        return $this->page($scope, $afterArtifactId, $limit, true, $now);
    }

    public function hasAnyActiveReference(
        ArtifactMaintenanceScope $scope,
        ContentAddress $contentAddress,
    ): bool {
        $this->descriptorChecks++;

        if ($this->descriptorAppearsOnCheck !== null && $this->descriptorChecks >= $this->descriptorAppearsOnCheck) {
            return true;
        }

        $address = (string) $contentAddress;

        foreach ($this->records as $record) {
            if ($scope->storageNamespace->equals($record->storageNamespace)
                && hash_equals($address, (string) $record->object->contentAddress)) {
                return true;
            }
        }

        return false;
    }

    public function hasOtherActiveReference(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool {
        foreach ($this->records as $candidate) {
            if ($candidate->id !== $record->id
                && $scope->storageNamespace->equals($candidate->storageNamespace)
                && hash_equals((string) $record->object->contentAddress, (string) $candidate->object->contentAddress)) {
                return true;
            }
        }

        return false;
    }

    public function quarantine(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): bool {
        if ($this->quarantineFails || ! isset($this->records[$record->id])) {
            return false;
        }

        $this->log->entries[] = 'quarantine:'.$record->id;

        if ($record->status === ArtifactStatus::Ready) {
            $this->quarantined[] = $record->id;
            $this->records[$record->id] = new ArtifactMaintenanceRecord(
                $record->id,
                $record->connectionKey,
                $record->storageNamespace,
                $record->object,
                ArtifactStatus::Quarantined,
                $record->readyAt,
                $record->expiresAt,
            );
        }

        return true;
    }

    public function tombstone(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
        DateTimeImmutable $deletedAt,
    ): bool {
        if ($this->tombstoneFails || ! isset($this->records[$record->id])) {
            return false;
        }

        $this->log->entries[] = 'tombstone:'.$record->id;
        $this->tombstoned[] = $record->id;
        $this->terminalAddresses[(string) $record->object->contentAddress] = true;
        unset($this->records[$record->id]);

        return true;
    }

    private function page(
        ArtifactMaintenanceScope $scope,
        ?string $afterArtifactId,
        int $limit,
        bool $expiredOnly,
        ?DateTimeImmutable $now,
    ): ArtifactMaintenanceRecordPage {
        $records = array_values(array_filter(
            $this->records,
            static fn (ArtifactMaintenanceRecord $record): bool => $record->belongsTo($scope)
                && ($afterArtifactId === null || $record->id > $afterArtifactId)
                && (! $expiredOnly || ($record->expiresAt !== null && $now !== null && $record->expiresAt <= $now)),
        ));
        usort($records, static fn (ArtifactMaintenanceRecord $left, ArtifactMaintenanceRecord $right): int => $left->id <=> $right->id);
        $hasMore = count($records) > $limit;
        $records = array_slice($records, 0, $limit);
        $last = $records === [] ? null : $records[array_key_last($records)];

        $next = $hasMore && $last instanceof ArtifactMaintenanceRecord ? $last->id : null;

        if ($this->repeatArtifactCursor && $afterArtifactId !== null) {
            $next = $afterArtifactId;
        }

        return new ArtifactMaintenanceRecordPage($records, $next);
    }
}

final class S64FakeArtifactAddressLease extends ArtifactAddressLease
{
    private bool $released = false;

    public function __construct(private readonly S64FakeArtifactAddressLock $owner) {}

    public function assertOwned(): void
    {
        if ($this->released) {
            throw new LogicException('The fake artifact address lease is no longer owned.');
        }
    }

    public function renewFor(int $minimumOwnedSeconds): void
    {
        $this->assertOwned();

        if ($minimumOwnedSeconds < 1) {
            throw new LogicException('The fake artifact address lease renewal is invalid.');
        }

        $this->owner->renewals++;
    }

    public function release(): void
    {
        if (! $this->released) {
            $this->released = true;
            $this->owner->releases++;
        }
    }
}

final class S64FakeArtifactAddressLock implements ArtifactAddressLock
{
    /** @var list<string> */
    public array $acquired = [];

    public int $releases = 0;

    public int $renewals = 0;

    public function acquire(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactAddressLease {
        $this->acquired[] = (string) $contentAddress;

        return new S64FakeArtifactAddressLease($this);
    }
}

final class S64RenewableArtifactAddressLock implements ArtifactAddressLock
{
    private readonly ArrayStore $store;

    public function __construct(private readonly int $leaseSeconds = 30)
    {
        $this->store = new ArrayStore;
    }

    public function acquire(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactAddressLease {
        $lock = $this->store->lock(
            hash('sha256', $storageNamespace->fingerprintSha256()."\0".(string) $contentAddress),
            $this->leaseSeconds,
        );

        if (! $lock instanceof Lock) {
            throw new RuntimeException('The test lock is not renewable.');
        }

        $lock->block(0);

        return new S64RenewableArtifactAddressLease($lock);
    }
}

final class S64RenewableArtifactAddressLease extends ArtifactAddressLease
{
    private bool $released = false;

    private bool $ownershipLost = false;

    public function __construct(private readonly Lock $lock) {}

    public function assertOwned(): void
    {
        if ($this->released || $this->ownershipLost
            || ! $this->lock->isLocked()
            || ! $this->lock->isOwnedByCurrentProcess()) {
            $this->ownershipLost = true;

            throw new RuntimeException('The test artifact address lease ownership has been lost.');
        }
    }

    public function renewFor(int $minimumOwnedSeconds): void
    {
        $this->assertOwned();

        if ($this->lock->refresh($minimumOwnedSeconds) !== true) {
            $this->ownershipLost = true;

            throw new RuntimeException('The test artifact address lease could not be renewed.');
        }

        $this->assertOwned();
    }

    public function release(): void
    {
        if ($this->released || $this->ownershipLost) {
            return;
        }

        if ($this->lock->release() !== true) {
            throw new RuntimeException('The test artifact address lease could not be released.');
        }

        $this->released = true;
    }
}

function s64Now(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-26 12:00:00+00:00');
}

function s64Clock(): ArtifactMaintenanceClock
{
    return new S64FrozenArtifactClock(s64Now());
}

function s64Namespace(string $prefix = 'fakturownia/finalized'): ArtifactStorageNamespace
{
    return new ArtifactStorageNamespace('shared-artifacts', $prefix);
}

function s64Scope(
    ArtifactStorageTopology $topology = ArtifactStorageTopology::Shared,
    DeploymentStage $stage = DeploymentStage::Production,
    string $connectionKey = 'tenant:one',
    string $prefix = 'fakturownia/finalized',
): ArtifactMaintenanceScope {
    return new ArtifactMaintenanceScope($connectionKey, s64Namespace($prefix), $stage, $topology);
}

function s64Capabilities(bool $complete = true): ArtifactStoreCapabilities
{
    return new ArtifactStoreCapabilities($complete, $complete, $complete, $complete, $complete, $complete ? 10 : 0);
}

function s64Observation(
    string $expectedContents,
    ?DateTimeImmutable $lastModifiedAt = null,
    string $generation = 'generation-1',
): ArtifactObjectObservation {
    $address = ContentAddress::fromSha256(hash('sha256', $expectedContents));

    return new ArtifactObjectObservation(
        new ArtifactObjectDescriptor('shared-artifacts', $address, 'application/pdf', strlen($expectedContents)),
        $lastModifiedAt ?? s64Now()->modify('-25 hours'),
        hash('sha256', $generation),
    );
}

function s64Record(
    string $expectedContents,
    ArtifactStatus $status = ArtifactStatus::Ready,
    ?DateTimeImmutable $expiresAt = null,
    string $connectionKey = 'tenant:one',
    string $prefix = 'fakturownia/finalized',
    ?string $id = null,
): ArtifactMaintenanceRecord {
    $observation = s64Observation($expectedContents);

    $resolvedExpiry = $expiresAt ?? s64Now()->modify('-1 hour');

    return new ArtifactMaintenanceRecord(
        $id ?? (string) new Ulid,
        $connectionKey,
        s64Namespace($prefix),
        $observation->object,
        $status,
        $resolvedExpiry->modify('-90 days'),
        $resolvedExpiry,
    );
}

/** @return array{S64OperationLog, S64FakeArtifactStore, S64FakeArtifactRepository, S64FakeArtifactAddressLock} */
function s64Fakes(?ArtifactStoreCapabilities $capabilities = null): array
{
    $log = new S64OperationLog;

    return [
        $log,
        new S64FakeArtifactStore($capabilities ?? s64Capabilities(), $log),
        new S64FakeArtifactRepository($log),
        new S64FakeArtifactAddressLock,
    ];
}

function s64Manager(
    S64FakeArtifactRepository $repository,
    S64FakeArtifactStore $store,
    ArtifactAddressLock $lock,
    ?ArtifactMaintenancePolicy $policy = null,
    ?ArtifactMaintenanceClock $clock = null,
    ?ArtifactMaintenanceScope $scope = null,
): ArtifactMaintenanceManager {
    $reflection = new ReflectionClass(ArtifactMaintenanceManager::class);
    $manager = $reflection->newInstanceWithoutConstructor();
    $constructor = $reflection->getConstructor();

    if (! $constructor instanceof ReflectionMethod) {
        throw new RuntimeException('The artifact maintenance manager constructor is unavailable.');
    }

    $constructor->invoke(
        $manager,
        $scope ?? s64Scope(),
        $repository,
        $store,
        $lock,
        $policy ?? new ArtifactMaintenancePolicy,
        $clock ?? s64Clock(),
        $store->purgePermitIssuer,
        $store->purgePermitVerifier,
    );

    return $manager;
}

function s64HostileSerializedPayload(string $className, string $value): string
{
    return sprintf(
        'O:%d:"%s":1:{s:6:"bypass";s:%d:"%s";}',
        strlen($className),
        $className,
        strlen($value),
        $value,
    );
}

it('requires canonical scopes and explicit production storage topology', function (): void {
    expect(fn (): ArtifactStorageNamespace => new ArtifactStorageNamespace(
        'shared-artifacts',
        '../fakturownia',
    ))->toThrow(InvalidArgumentException::class);
    expect(fn (): SharedDatabaseArtifactLockConfiguration => new SharedDatabaseArtifactLockConfiguration(
        'shared',
        'fakturownia_testing',
        'database.internal',
        0,
        'public',
        'public',
        'fakturownia_artifacts',
        'fakturownia_artifact_locks',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): SharedDatabaseArtifactLockConfiguration => new SharedDatabaseArtifactLockConfiguration(
            'shared',
            'fakturownia_testing',
            'database.internal',
            5432,
            '../schema',
            'public',
            'fakturownia_artifacts',
            '../cache_locks',
        ))->toThrow(InvalidArgumentException::class);

    [, $store, $repository, $lock] = s64Fakes();
    $scope = s64Scope(ArtifactStorageTopology::Unverified);
    $policy = new ArtifactMaintenancePolicy;
    $manager = s64Manager($repository, $store, $lock, $policy, scope: $scope);

    expect($manager->doctor()->findings[0]->issue)
        ->toBe(ArtifactMaintenanceIssue::SharedStorageUnverified)
        ->and((new ArtifactMaintenancePolicy(requireSharedStorageInProduction: false))->permitsAudit($scope, s64Capabilities()))
        ->toBeFalse()
        ->and(fn () => $manager->sweep())
        ->toThrow(ArtifactMaintenanceBlocked::class)
        ->and(fn () => $manager->prune())
        ->toThrow(ArtifactMaintenanceBlocked::class);
});

it('allows a production single-host topology only through the explicit policy and store attestation', function (): void {
    $singleHostScope = s64Scope(ArtifactStorageTopology::SingleExecutionHost);
    $unverifiedSingleHost = new ArtifactStoreCapabilities(true, false, true, true, true, 10);

    expect((new ArtifactMaintenancePolicy)->permitsAudit($singleHostScope, s64Capabilities()))
        ->toBeFalse()
        ->and((new ArtifactMaintenancePolicy(requireSharedStorageInProduction: false))
            ->permitsAudit($singleHostScope, s64Capabilities()))
        ->toBeTrue()
        ->and((new ArtifactMaintenancePolicy(requireSharedStorageInProduction: false))
            ->permitsAudit($singleHostScope, $unverifiedSingleHost))
        ->toBeFalse();
});

it('pins the complete maintenance scope in the manager and rejects every caller supplied scope before IO', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $manager = s64Manager($repository, $store, $lock);
    $doctor = new ReflectionMethod(ArtifactMaintenanceManager::class, 'doctor');
    $prune = new ReflectionMethod(ArtifactMaintenanceManager::class, 'prune');
    $sweep = new ReflectionMethod(ArtifactMaintenanceManager::class, 'sweep');
    $hostileScopes = [
        s64Scope(connectionKey: 'tenant:hostile'),
        new ArtifactMaintenanceScope(
            'tenant:one',
            new ArtifactStorageNamespace('hostile-disk', 'fakturownia/finalized'),
            DeploymentStage::Production,
            ArtifactStorageTopology::Shared,
        ),
        s64Scope(prefix: 'fakturownia/hostile'),
        s64Scope(stage: DeploymentStage::NonProduction),
        s64Scope(topology: ArtifactStorageTopology::SingleExecutionHost),
    ];

    expect($doctor->getNumberOfParameters())->toBe(0)
        ->and($prune->getParameters()[0]->getType()?->__toString())->toBe('?string')
        ->and($sweep->getParameters()[0]->getType()?->__toString())->toBe('?'.ContentAddress::class);

    foreach ($hostileScopes as $hostileScope) {
        expect(fn (): mixed => $doctor->invoke($manager, $hostileScope))
            ->toThrow(InvalidArgumentException::class, 'factory-owned')
            ->and(fn (): mixed => $prune->invoke($manager, $hostileScope))
            ->toThrow(TypeError::class)
            ->and(fn (): mixed => $sweep->invoke($manager, $hostileScope))
            ->toThrow(TypeError::class);
    }

    expect($store->capabilityChecks)->toBe(0)
        ->and($store->scanChecks)->toBe(0)
        ->and($repository->auditChecks)->toBe(0);
});

it('blocks destructive maintenance when generation-safe store capabilities are incomplete', function (): void {
    [, $store, $repository, $lock] = s64Fakes(new ArtifactStoreCapabilities(true, true, true, true, false, 0));
    $scope = s64Scope();
    $policy = new ArtifactMaintenancePolicy;
    $manager = s64Manager($repository, $store, $lock, $policy);

    expect($manager->doctor()->findings[0]->issue)
        ->toBe(ArtifactMaintenanceIssue::MaintenanceCapabilitiesIncomplete)
        ->and(fn () => $manager->sweep())
        ->toThrow(ArtifactMaintenanceBlocked::class)
        ->and(fn () => $manager->prune())
        ->toThrow(ArtifactMaintenanceBlocked::class);
});

it('keeps doctor read only and fails closed on missing bytes checksum drift and orphan backlog', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $healthy = s64Record('healthy-pdf', expiresAt: s64Now()->modify('+1 day'));
    $missing = s64Record('missing-pdf', expiresAt: s64Now()->modify('+1 day'));
    $corrupt = s64Record('expected-pdf', expiresAt: s64Now()->modify('+1 day'));
    $orphan = s64Observation('orphan-pdf');

    foreach ([$healthy, $missing, $corrupt] as $record) {
        $repository->records[$record->id] = $record;
    }

    $healthyObservation = s64Observation('healthy-pdf');
    $corruptObservation = s64Observation('expected-pdf');
    $store->observations[(string) $healthyObservation->object->contentAddress] = $healthyObservation;
    $store->observations[(string) $corruptObservation->object->contentAddress] = $corruptObservation;
    $store->observations[(string) $orphan->object->contentAddress] = $orphan;
    $store->contents[(string) $healthyObservation->object->contentAddress] = 'healthy-pdf';
    $store->contents[(string) $corruptObservation->object->contentAddress] = 'tampered-pdf';
    $store->contents[(string) $orphan->object->contentAddress] = 'orphan-pdf';

    $report = s64Manager($repository, $store, $lock)->doctor();
    $issues = array_map(static fn ($finding) => $finding->issue, $report->findings);

    expect($report->passes())->toBeFalse()
        ->and($issues)->toContain(ArtifactMaintenanceIssue::MissingObject)
        ->and($issues)->toContain(ArtifactMaintenanceIssue::ChecksumMismatch)
        ->and($issues)->toContain(ArtifactMaintenanceIssue::OrphanBacklog)
        ->and($repository->quarantined)->toBe([])
        ->and($repository->tombstoned)->toBe([])
        ->and($store->purgedOrphans)->toBe([])
        ->and($store->purgedExpired)->toBe([]);
});

it('deletes only old unreferenced orphans after repeated DB proof under an address lease', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $orphan = s64Observation('old-orphan');
    $referenced = s64Record('referenced-object', expiresAt: s64Now()->modify('+1 day'));
    $fresh = s64Observation('fresh-object', s64Now()->modify('-1 hour'));

    $repository->records[$referenced->id] = $referenced;
    foreach ([$orphan, s64Observation('referenced-object'), $fresh] as $observation) {
        $store->observations[(string) $observation->object->contentAddress] = $observation;
        $store->contents[(string) $observation->object->contentAddress] = match ((string) $observation->object->contentAddress) {
            (string) $orphan->object->contentAddress => 'old-orphan',
            (string) $fresh->object->contentAddress => 'fresh-object',
            default => 'referenced-object',
        };
    }
    $store->includeFreshInScan = true;

    $report = s64Manager($repository, $store, $lock)->sweep();

    expect($report->objectsDeleted)->toBe(1)
        ->and($store->purgedOrphans)->toBe([(string) $orphan->object->contentAddress])
        ->and($report->findings[0]->issue)->toBe(ArtifactMaintenanceIssue::FreshObjectInOrphanScan)
        ->and($lock->acquired)->toBe([(string) $orphan->object->contentAddress])
        ->and($lock->releases)->toBe(1);
});

it('does not delete an orphan when a descriptor appears during the locked final check', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $orphan = s64Observation('racing-orphan');
    $store->observations[(string) $orphan->object->contentAddress] = $orphan;
    $store->contents[(string) $orphan->object->contentAddress] = 'racing-orphan';
    $repository->descriptorAppearsOnCheck = 2;

    $report = s64Manager($repository, $store, $lock)->sweep();

    expect($report->objectsDeleted)->toBe(0)
        ->and($store->purgedOrphans)->toBe([])
        ->and($lock->releases)->toBe(1);
});

it('claims verifies deletes and tombstones an expired artifact in explicit non atomic order', function (): void {
    [$log, $store, $repository, $lock] = s64Fakes();
    $record = s64Record('expired-pdf');
    $observation = s64Observation('expired-pdf');
    $repository->records[$record->id] = $record;
    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'expired-pdf';

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->objectsDeleted)->toBe(1)
        ->and($report->tombstoned)->toBe(1)
        ->and($report->quarantined)->toBe(1)
        ->and($report->findings)->toBe([])
        ->and($log->entries)->toBe([
            'quarantine:'.$record->id,
            'purge:'.$record->id,
            'tombstone:'.$record->id,
        ])
        ->and($lock->releases)->toBe(1);
});

it('keeps shared content when another connection has an active descriptor', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $record = s64Record('shared-content');
    $other = s64Record('shared-content', expiresAt: s64Now()->modify('+10 days'), connectionKey: 'tenant:other');
    $observation = s64Observation('shared-content');
    $repository->records[$record->id] = $record;
    $repository->records[$other->id] = $other;
    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'shared-content';

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->objectsDeleted)->toBe(0)
        ->and($report->tombstoned)->toBe(1)
        ->and($store->purgedExpired)->toBe([])
        ->and($store->observations)->toHaveKey((string) $observation->object->contentAddress)
        ->and($repository->records)->toHaveKey($other->id);
});

it('quarantines missing or corrupt expired objects without deleting or tombstoning them', function (string $expected, ?string $stored): void {
    [, $store, $repository, $lock] = s64Fakes();
    $record = s64Record($expected);
    $repository->records[$record->id] = $record;

    if ($stored !== null) {
        $observation = s64Observation($expected);
        $store->observations[(string) $observation->object->contentAddress] = $observation;
        $store->contents[(string) $observation->object->contentAddress] = $stored;
    }

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->passes())->toBeFalse()
        ->and($report->quarantined)->toBe(1)
        ->and($report->objectsDeleted)->toBe(0)
        ->and($report->tombstoned)->toBe(0)
        ->and($repository->records[$record->id]->status)->toBe(ArtifactStatus::Quarantined)
        ->and($store->purgedExpired)->toBe([]);
})->with([
    'missing' => ['missing-expired', null],
    'checksum mismatch' => ['expected-hash', 'tampered-hash'],
]);

it('finishes a tombstone on the next retention pass when a previously quarantined expired object is absent', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $record = s64Record('already-removed', ArtifactStatus::Quarantined);
    $repository->records[$record->id] = $record;

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->tombstoned)->toBe(1)
        ->and($report->objectsDeleted)->toBe(0)
        ->and($report->findings)->toBe([])
        ->and($repository->records)->not->toHaveKey($record->id);
});

it('does not tombstone when the store rejects a changed generation during conditional purge', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $record = s64Record('generation-race');
    $observation = s64Observation('generation-race');
    $repository->records[$record->id] = $record;
    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'generation-race';
    $store->forcedPurgeOutcome = ArtifactPurgeOutcome::RejectedChanged;

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->passes())->toBeFalse()
        ->and($report->findings[0]->issue)->toBe(ArtifactMaintenanceIssue::ObjectChangedBeforeDelete)
        ->and($report->tombstoned)->toBe(0)
        ->and($repository->records[$record->id]->status)->toBe(ArtifactStatus::Quarantined)
        ->and($store->observations)->toHaveKey((string) $observation->object->contentAddress);
});

it('never exposes a raw delete or serializable address lease', function (): void {
    $storeMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ArtifactMaintenanceStore::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $lockMethod = new ReflectionMethod(ArtifactAddressLock::class, 'acquire');
    $lease = new S64FakeArtifactAddressLease(new S64FakeArtifactAddressLock);
    $managerConstructor = (new ReflectionClass(ArtifactMaintenanceManager::class))->getConstructor();
    $factoryMethod = new ReflectionMethod(PostgresArtifactMaintenanceManagerFactory::class, 'make');

    expect($storeMethods)->not->toContain('delete')
        ->and($storeMethods)->toContain('purgeOrphan', 'purgeExpired')
        ->and($lockMethod->getReturnType()?->__toString())->toBe(ArtifactAddressLease::class)
        ->and($managerConstructor?->isPrivate())->toBeTrue()
        ->and($factoryMethod->getNumberOfRequiredParameters())->toBe(0)
        ->and($lease->__debugInfo())->toBe(['lease' => '[REDACTED]'])
        ->and(fn () => clone $lease)->toThrow(LogicException::class)
        ->and(fn () => serialize($lease))->toThrow(LogicException::class);
});

it('requires a manager-owned one-shot permit before any direct object purge', function (): void {
    [, $store] = s64Fakes();
    $scope = s64Scope();
    $first = s64Observation('authority-first');
    $second = s64Observation('authority-second');
    $deadline = new ArtifactPurgeDeadline(s64Now(), 10);

    foreach ([$first, $second] as $observation) {
        $address = (string) $observation->object->contentAddress;
        $store->observations[$address] = $observation;
        $store->contents[$address] = $observation === $first ? 'authority-first' : 'authority-second';
    }

    $independentIssuer = ArtifactPurgePermitIssuer::create();
    $independentPermit = $independentIssuer->issueOrphan($scope->storageNamespace, $first, $deadline);

    expect(fn () => $store->purgeOrphan(
        $independentPermit,
        $scope->storageNamespace,
        $first,
        $deadline,
    ))->toThrow(ArtifactPurgeUnauthorized::class)
        ->and($store->observations)->toHaveKey((string) $first->object->contentAddress);

    $forged = (new ReflectionClass(ArtifactPurgePermit::class))->newInstanceWithoutConstructor();
    expect(fn () => $store->purgeOrphan(
        $forged,
        $scope->storageNamespace,
        $first,
        $deadline,
    ))->toThrow(Error::class)
        ->and($store->observations)->toHaveKey((string) $first->object->contentAddress);

    $targetBoundPermit = $store->purgePermitIssuer->issueOrphan($scope->storageNamespace, $first, $deadline);
    expect(fn () => $store->purgeOrphan(
        $targetBoundPermit,
        $scope->storageNamespace,
        $second,
        $deadline,
    ))->toThrow(ArtifactPurgeUnauthorized::class)
        ->and($store->observations)->toHaveKey((string) $second->object->contentAddress)
        ->and($store->purgeOrphan(
            $targetBoundPermit,
            $scope->storageNamespace,
            $first,
            $deadline,
        ))->toBe(ArtifactPurgeOutcome::Deleted);

    $firstAddress = (string) $first->object->contentAddress;
    $store->observations[$firstAddress] = $first;
    $store->contents[$firstAddress] = 'authority-first';

    expect(fn () => $store->purgeOrphan(
        $targetBoundPermit,
        $scope->storageNamespace,
        $first,
        $deadline,
    ))->toThrow(ArtifactPurgeUnauthorized::class)
        ->and($store->observations)->toHaveKey($firstAddress);
});

it('rejects native serialization hydration for every artifact maintenance value boundary', function (): void {
    $record = s64Record('native-serialization');
    $observation = s64Observation('native-serialization');
    $deadline = new ArtifactPurgeDeadline(s64Now(), 10);

    foreach ([$record->object->contentAddress, $record->storageNamespace, $observation, $record, $deadline, s64Scope()] as $value) {
        expect(fn (): string => serialize($value))->toThrow(LogicException::class);
    }

    $classes = [
        ContentAddress::class,
        ArtifactStorageNamespace::class,
        ArtifactObjectDescriptor::class,
        ArtifactObjectObservation::class,
        ArtifactMaintenanceRecord::class,
        ArtifactMaintenanceRecordPage::class,
        ArtifactMaintenanceScope::class,
        ArtifactMaintenancePolicy::class,
        ArtifactObjectPage::class,
        ArtifactPurgeDeadline::class,
        ArtifactStoreCapabilities::class,
        ArtifactPurgePermit::class,
        ArtifactPurgePermitIssuer::class,
        ArtifactPurgePermitVerifier::class,
        SharedDatabaseArtifactLockConfiguration::class,
    ];

    foreach ($classes as $className) {
        $payload = s64HostileSerializedPayload(
            $className,
            $className === ArtifactStorageNamespace::class ? '../escape' : '../../escape',
        );

        expect(fn (): mixed => unserialize($payload))->toThrow(LogicException::class);
    }
});

it('fails closed without lifecycle writes when persisted expiry differs from the pinned policy', function (?DateTimeImmutable $expiresAt): void {
    [, $store, $repository, $lock] = s64Fakes();
    $readyAt = s64Now()->modify('-91 days');
    $observation = s64Observation('retention-mismatch');
    $record = new ArtifactMaintenanceRecord(
        (string) new Ulid,
        'tenant:one',
        s64Namespace(),
        $observation->object,
        ArtifactStatus::Ready,
        $readyAt,
        $expiresAt,
    );
    $repository->records[$record->id] = $record;
    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'retention-mismatch';

    $report = s64Manager($repository, $store, $lock)->prune();

    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->issue)->toBe(ArtifactMaintenanceIssue::RetentionPolicyMismatch)
        ->and($report->quarantined)->toBe(0)
        ->and($report->objectsDeleted)->toBe(0)
        ->and($report->tombstoned)->toBe(0)
        ->and($repository->quarantined)->toBe([])
        ->and($repository->tombstoned)->toBe([])
        ->and($store->purgedExpired)->toBe([]);
})->with([
    'early' => [s64Now()->modify('-1 day -1 second')],
    'late' => [s64Now()->modify('-1 day +1 second')],
    'null' => [null],
]);

it('runs doctor only as a complete origin scan and rejects a non-progressing tail', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $corrupt = s64Record(
        'doctor-corrupt',
        expiresAt: s64Now()->modify('+1 day'),
        id: '00000000000000000000000001',
    );
    $healthy = s64Record(
        'doctor-healthy',
        expiresAt: s64Now()->modify('+1 day'),
        id: '00000000000000000000000002',
    );

    foreach ([$corrupt, $healthy] as $record) {
        $repository->records[$record->id] = $record;
        $observation = s64Observation($record === $corrupt ? 'doctor-corrupt' : 'doctor-healthy');
        $address = (string) $observation->object->contentAddress;
        $store->observations[$address] = $observation;
        $store->contents[$address] = $record === $corrupt ? 'xxxxxxxxxxxxxx' : 'doctor-healthy';
    }

    $manager = s64Manager($repository, $store, $lock, new ArtifactMaintenancePolicy(batchSize: 1));
    $report = $manager->doctor();
    $issues = array_map(static fn ($finding) => $finding->issue, $report->findings);

    expect((new ReflectionMethod(ArtifactMaintenanceManager::class, 'doctor'))->getNumberOfParameters())->toBe(0)
        ->and($report->examined)->toBe(2)
        ->and($report->passes())->toBeFalse()
        ->and($issues)->toContain(ArtifactMaintenanceIssue::ChecksumMismatch);

    $repository->repeatArtifactCursor = true;
    expect(fn () => $manager->doctor())
        ->toThrow(RuntimeException::class, 'did not complete with a progressing bounded cursor');
});

it('scans every doctor row while retaining only a bounded finding sample', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $findingCount = ArtifactMaintenanceReport::MAXIMUM_FINDING_SAMPLE + 25;

    for ($index = 0; $index < $findingCount; $index++) {
        $record = s64Record("bounded-finding-{$index}", expiresAt: s64Now()->modify('+1 day'));
        $repository->records[$record->id] = $record;
    }

    $report = s64Manager(
        $repository,
        $store,
        $lock,
        new ArtifactMaintenancePolicy(batchSize: 17),
    )->doctor();

    expect($report->examined)->toBe($findingCount)
        ->and($report->findings)->toHaveCount(ArtifactMaintenanceReport::MAXIMUM_FINDING_SAMPLE)
        ->and($report->totalFindings)->toBe($findingCount)
        ->and($report->findingsTruncated)->toBeTrue()
        ->and($report->passes())->toBeFalse();
});

it('coordinates one address across connections without conflating storage namespaces', function (): void {
    $lock = new S64RenewableArtifactAddressLock;
    $address = ContentAddress::fromSha256(hash('sha256', 'shared-lock-address'));
    $writerLease = $lock->acquire(s64Namespace(), $address);

    expect(fn (): ArtifactAddressLease => $lock->acquire(s64Scope(connectionKey: 'tenant:other')->storageNamespace, $address))
        ->toThrow(LockTimeoutException::class);

    $otherNamespaceLease = $lock->acquire(s64Namespace('fakturownia/other'), $address);
    $otherNamespaceLease->release();
    $writerLease->release();
    $writerLease->release();

    $productionConstructor = new ReflectionMethod(CacheArtifactAddressLock::class, '__construct');
    expect($productionConstructor->getParameters()[0]->getType()?->__toString())
        ->toBe(Connection::class);
});

it('fails closed when a renewable address lease loses ownership', function (): void {
    Carbon::setTestNow('2026-08-26 12:00:00+00:00');

    try {
        $lock = new S64RenewableArtifactAddressLock(1);
        $address = ContentAddress::fromSha256(hash('sha256', 'expiring-lock-address'));
        $lease = $lock->acquire(s64Namespace(), $address);
        Carbon::setTestNow('2026-08-26 12:00:02+00:00');

        expect(fn () => $lease->renewFor(10))->toThrow(RuntimeException::class);

        $lease->release();
        $replacement = $lock->acquire(s64Namespace(), $address);
        $replacement->release();
    } finally {
        Carbon::setTestNow();
    }
});

it('does not delete when a slow purge exceeds its deadline and a writer takes the lease', function (): void {
    Carbon::setTestNow(s64Now());
    $writerLease = null;

    try {
        [, $store, $repository] = s64Fakes();
        $lock = new S64RenewableArtifactAddressLock(1);
        $record = s64Record('slow-purge-object');
        $observation = s64Observation('slow-purge-object');
        $repository->records[$record->id] = $record;
        $store->observations[(string) $observation->object->contentAddress] = $observation;
        $store->contents[(string) $observation->object->contentAddress] = 'slow-purge-object';
        $store->advanceClockDuringPurgeSeconds = 16;
        $store->concurrentWriterLock = $lock;

        $manager = s64Manager($repository, $store, $lock);

        expect(fn (): mixed => $manager->prune())->toThrow(RuntimeException::class)
            ->and($store->observations)->toHaveKey((string) $observation->object->contentAddress)
            ->and($store->purgedExpired)->toBe([])
            ->and($repository->tombstoned)->toBe([])
            ->and($repository->records[$record->id]->status)->toBe(ArtifactStatus::Quarantined)
            ->and($store->concurrentWriterLease)->toBeInstanceOf(ArtifactAddressLease::class);

        $writerLease = $store->concurrentWriterLease;
    } finally {
        $writerLease?->release();
        Carbon::setTestNow();
    }
});

it('keeps destructive time out of per-call APIs and removes residual objects behind tombstones', function (): void {
    [, $store, $repository, $lock] = s64Fakes();
    $record = s64Record('residual-object');
    $observation = s64Observation('residual-object');
    $repository->records[$record->id] = $record;
    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'residual-object';

    $manager = s64Manager($repository, $store, $lock);
    expect($manager->prune()->tombstoned)->toBe(1);

    $store->observations[(string) $observation->object->contentAddress] = $observation;
    $store->contents[(string) $observation->object->contentAddress] = 'residual-object';
    expect($manager->sweep()->objectsDeleted)->toBe(1)
        ->and($repository->terminalAddresses)->toHaveKey((string) $observation->object->contentAddress)
        ->and($store->observations)->not->toHaveKey((string) $observation->object->contentAddress);

    foreach ([
        new ReflectionMethod(ArtifactMaintenanceManager::class, 'prune'),
        new ReflectionMethod(ArtifactMaintenanceManager::class, 'sweep'),
        new ReflectionMethod(ArtifactMaintenanceManager::class, 'doctor'),
    ] as $method) {
        $parameterTypes = array_map(
            static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString(),
            $method->getParameters(),
        );

        expect($parameterTypes)->not->toContain(DateTimeImmutable::class);
    }
});
