<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Sync\IncrementalSyncCheckpoint;
use Cieplik206\Fakturownia\Stateful\Sync\IncrementalSyncObservation;
use Cieplik206\Fakturownia\Stateful\Sync\IncrementalSyncPlanner;
use Cieplik206\Fakturownia\Stateful\Sync\RemoteSyncCursor;
use Cieplik206\Fakturownia\Stateful\Sync\SyncCheckpointLease;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotAttestor;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;

final class S816SnapshotKeyRing implements LookupHmacKeyRing
{
    public function activeVersion(): int
    {
        return 3;
    }

    /** @return list<int> */
    public function readableVersions(): array
    {
        return [2, 3];
    }

    public function hmacSha256(int $keyVersion, string $material): string
    {
        return hash_hmac('sha256', $material, "sync-key-{$keyVersion}");
    }
}

function s816Observation(
    SyncIntegrityScope $scope,
    string $remoteId,
    string $updatedAt,
    string $value,
    int $keyVersion = 3,
): IncrementalSyncObservation {
    $attestor = new SnapshotAttestor(new S816SnapshotKeyRing, new CanonicalJsonV1);

    return new IncrementalSyncObservation(
        new RemoteSyncCursor(new DateTimeImmutable($updatedAt), $remoteId),
        $attestor->attest($scope, $remoteId, ['value' => $value], $keyVersion),
        $attestor,
    );
}

it('orders a high-water cursor by UTC instant and then remote ID', function (): void {
    $earlier = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T09:59:59.999999+00:00'), '999');
    $lowerId = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00.000000+00:00'), '100');
    $higherId = RemoteSyncCursor::fromStored('2026-08-26 10:00:00.000000+00:00', '101');

    expect($lowerId->isAfter($earlier))->toBeTrue()
        ->and($higherId->isAfter($lowerId))->toBeTrue()
        ->and($higherId->timestamp())->toBe('2026-08-26 10:00:00.000000+00:00');
});

it('derives a bounded overlap query start without mutating the checkpoint', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $cursor = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00+00:00'), '42');
    $checkpoint = new IncrementalSyncCheckpoint($scope, $cursor, 7);
    $planner = new IncrementalSyncPlanner;

    expect($planner->queryStartAt($checkpoint, 300)?->format(DATE_ATOM))
        ->toBe('2026-08-26T09:55:00+00:00')
        ->and($checkpoint->cursor)->toBe($cursor)
        ->and(fn (): ?DateTimeImmutable => $planner->queryStartAt($checkpoint, 604_801))
        ->toThrow(InvalidArgumentException::class, 'between zero and seven days');
});

it('deduplicates one bounded page and advances only beyond the prior high-water mark', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'products');
    $checkpointCursor = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00+00:00'), '20');
    $checkpoint = new IncrementalSyncCheckpoint($scope, $checkpointCursor, 2);
    $olderDuplicate = s816Observation($scope, '42', '2026-08-26T09:59:00+00:00', 'old');
    $newerDuplicate = s816Observation($scope, '42', '2026-08-26T10:01:00+00:00', 'new');
    $overlapObservation = s816Observation($scope, '10', '2026-08-26T09:58:00+00:00', 'overlap');

    $page = (new IncrementalSyncPlanner)->preparePage(
        $checkpoint,
        [$newerDuplicate, $overlapObservation, $olderDuplicate],
    );

    expect($page->inputCount)->toBe(3)
        ->and($page->duplicateCount)->toBe(1)
        ->and($page->observations)->toBe([$overlapObservation, $newerDuplicate])
        ->and($page->nextCursor)->toBe($newerDuplicate->cursor);
});

it('keeps a prior checkpoint when an overlap page contains only older observations', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'payments');
    $cursor = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00+00:00'), '20');
    $checkpoint = new IncrementalSyncCheckpoint($scope, $cursor, 2);

    $page = (new IncrementalSyncPlanner)->preparePage($checkpoint, [
        s816Observation($scope, '10', '2026-08-26T09:58:00+00:00', 'overlap'),
    ]);

    expect($page->nextCursor)->toBe($cursor)
        ->and($page->observations)->toHaveCount(1);
});

it('rejects oversized mixed and contradictory pages', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $otherScope = new SyncIntegrityScope('tenant:secondary', 'clients');
    $checkpoint = new IncrementalSyncCheckpoint($scope, null, 0);
    $planner = new IncrementalSyncPlanner;
    $sameCursorFirst = s816Observation($scope, '42', '2026-08-26T10:00:00+00:00', 'first');
    $sameCursorSecond = s816Observation($scope, '42', '2026-08-26T10:00:00+00:00', 'second');

    expect(fn () => $planner->preparePage($checkpoint, array_fill(0, 101, $sameCursorFirst)))
        ->toThrow(InvalidArgumentException::class, 'at most 100 observations')
        ->and(fn () => $planner->preparePage($checkpoint, [
            s816Observation($otherScope, '42', '2026-08-26T10:00:00+00:00', 'first'),
        ]))->toThrow(InvalidArgumentException::class, 'cannot mix scopes')
        ->and(fn () => $planner->preparePage($checkpoint, [
            $sameCursorFirst,
            s816Observation($scope, '43', '2026-08-26T10:00:00+00:00', 'first', 2),
        ]))->toThrow(InvalidArgumentException::class, 'cannot mix HMAC key versions')
        ->and(fn () => $planner->preparePage($checkpoint, [$sameCursorFirst, $sameCursorSecond]))
        ->toThrow(InvalidArgumentException::class, 'contradictory snapshots');
});

it('rejects an observation whose cursor is not bound to its snapshot identity', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $attestor = new SnapshotAttestor(new S816SnapshotKeyRing, new CanonicalJsonV1);

    expect(fn (): IncrementalSyncObservation => new IncrementalSyncObservation(
        new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00+00:00'), '43'),
        $attestor->attest($scope, '42', ['value' => 'snapshot']),
        $attestor,
    ))->toThrow(InvalidArgumentException::class, 'identify different remote records');
});

it('issues opaque fenced leases with strict UTC expiry semantics', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'invoices');
    $acquiredAt = new DateTimeImmutable('2026-08-26T10:00:00+00:00');
    $expiresAt = new DateTimeImmutable('2026-08-26T10:05:00+00:00');
    $lease = SyncCheckpointLease::issue($scope, 4, $acquiredAt, $expiresAt);

    expect($lease->authenticates($lease->tokenSha256()))->toBeTrue()
        ->and($lease->authenticates(str_repeat('a', 64)))->toBeFalse()
        ->and($lease->isExpiredAt(new DateTimeImmutable('2026-08-26T10:04:59.999999+00:00')))->toBeFalse()
        ->and($lease->isExpiredAt($expiresAt))->toBeTrue()
        ->and($lease->__debugInfo())->toBe(['sync_checkpoint_lease' => '[REDACTED]'])
        ->and(json_encode($lease->__debugInfo(), JSON_THROW_ON_ERROR))->not->toContain($lease->tokenSha256());
});

it('redacts the high-water remote identity from debug output', function (): void {
    $cursor = new RemoteSyncCursor(new DateTimeImmutable('2026-08-26T10:00:00+00:00'), 'sensitive-remote-id');

    expect($cursor->remoteId())->toBe('sensitive-remote-id')
        ->and($cursor->__debugInfo())->toBe([
            'updated_at' => '2026-08-26T10:00:00.000000Z',
            'remote_id' => '[REDACTED]',
        ])
        ->and(json_encode($cursor->__debugInfo(), JSON_THROW_ON_ERROR))->not->toContain('sensitive-remote-id');
});
