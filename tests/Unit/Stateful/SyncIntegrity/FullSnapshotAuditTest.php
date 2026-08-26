<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\SyncIntegrity\FullSnapshotAuditor;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\FullSnapshotAuditReport;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotAttestation;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotAttestor;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SnapshotHmac;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\StoredSnapshot;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityMutation;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityMutationKind;
use Cieplik206\Fakturownia\Stateful\SyncIntegrity\SyncIntegrityScope;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;

final class S817SnapshotKeyRing implements LookupHmacKeyRing
{
    /** @param non-empty-array<int, non-empty-string> $keys */
    public function __construct(
        private readonly array $keys,
        private readonly int $active,
    ) {}

    public function activeVersion(): int
    {
        return $this->active;
    }

    /** @return list<int> */
    public function readableVersions(): array
    {
        return array_keys($this->keys);
    }

    public function hmacSha256(int $keyVersion, string $material): string
    {
        return hash_hmac('sha256', $material, $this->keys[$keyVersion]);
    }
}

function s817Attestor(): SnapshotAttestor
{
    return new SnapshotAttestor(
        new S817SnapshotKeyRing([1 => 'old-integrity-key', 2 => 'current-integrity-key'], 2),
        new CanonicalJsonV1,
    );
}

function s817Stored(SnapshotAttestation $attestation, bool $tombstoned = false): StoredSnapshot
{
    $firstSeenAt = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
    $lastSeenAt = new DateTimeImmutable('2026-08-20T00:00:00+00:00');

    return new StoredSnapshot(
        attestation: $attestation,
        firstSeenAt: $firstSeenAt,
        lastSeenAt: $lastSeenAt,
        tombstonedAt: $tombstoned ? new DateTimeImmutable('2026-08-21T00:00:00+00:00') : null,
    );
}

it('attests canonical snapshots without retaining remote identifiers or payloads', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $first = $attestor->attest($scope, 'remote-client-42', ['name' => 'Marruni', 'active' => true]);
    $reordered = $attestor->attest($scope, 'remote-client-42', ['active' => true, 'name' => 'Marruni']);
    $canonicalObject = $attestor->attest(
        $scope,
        'remote-client-42',
        new CanonicalObject(['active' => true, 'name' => 'Marruni']),
    );

    expect($first->sameSnapshot($reordered))->toBeTrue()
        ->and($first->sameSnapshot($canonicalObject))->toBeTrue()
        ->and($first->keyVersion())->toBe(2)
        ->and(get_object_vars($first))->toHaveKeys(['scope', 'remoteIdentity', 'snapshot'])
        ->and(json_encode(get_object_vars($first), JSON_THROW_ON_ERROR))->not->toContain('remote-client-42')
        ->not->toContain('Marruni');
});

it('domain-separates snapshot HMACs by scope and supports a pinned readable key version', function (): void {
    $attestor = s817Attestor();
    $primary = new SyncIntegrityScope('tenant:primary', 'clients');
    $secondary = new SyncIntegrityScope('tenant:secondary', 'clients');
    $current = $attestor->attest($primary, '42', ['name' => 'Marruni']);
    $otherScope = $attestor->attest($secondary, '42', ['name' => 'Marruni']);
    $oldKey = $attestor->attest($primary, '42', ['name' => 'Marruni'], 1);

    expect($current->remoteIdentity->equals($otherScope->remoteIdentity))->toBeFalse()
        ->and($current->snapshot->equals($otherScope->snapshot))->toBeFalse()
        ->and($oldKey->keyVersion())->toBe(1)
        ->and($oldKey->remoteIdentity->equals($current->remoteIdentity))->toBeFalse();

    expect(fn (): SnapshotAttestation => $attestor->attest($primary, '42', [], 3))
        ->toThrow(InvalidArgumentException::class, 'not readable');
});

it('rejects oversized snapshot graphs before canonical encoding', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');

    expect(fn (): SnapshotAttestation => $attestor->attest($scope, '42', str_repeat('x', 131_073)))
        ->toThrow(InvalidArgumentException::class, 'oversized or invalid string')
        ->and(fn (): SnapshotAttestation => $attestor->attest(
            $scope,
            '42',
            array_fill(0, 3_000, str_repeat('x', 100)),
        ))->toThrow(InvalidArgumentException::class, 'structural limits')
        ->and(fn (): SnapshotAttestation => $attestor->attest($scope, '42', str_repeat("\x01", 100_000)))
        ->toThrow(InvalidArgumentException::class, 'canonical snapshot exceeds the byte limit');
});

it('detects additions changes restorations and missing remote records in one completed full audit', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'products');
    $unchanged = $attestor->attest($scope, '1', ['name' => 'A']);
    $changedBefore = $attestor->attest($scope, '2', ['name' => 'B']);
    $changedAfter = $attestor->attest($scope, '2', ['name' => 'B2']);
    $missing = $attestor->attest($scope, '3', ['name' => 'C']);
    $restored = $attestor->attest($scope, '4', ['name' => 'D']);
    $added = $attestor->attest($scope, '5', ['name' => 'E']);
    $completedAt = new DateTimeImmutable('2026-08-26T10:00:00+00:00');

    $report = (new FullSnapshotAuditor)->audit(
        scope: $scope,
        keyVersion: 2,
        storedSnapshots: [
            s817Stored($unchanged),
            s817Stored($changedBefore),
            s817Stored($missing),
            s817Stored($restored, tombstoned: true),
        ],
        observedSnapshots: [$unchanged, $changedAfter, $restored, $added],
        completedAt: $completedAt,
    );

    expect($report->storedCount)->toBe(4)
        ->and($report->observedCount)->toBe(4)
        ->and($report->unchangedCount)->toBe(1)
        ->and($report->hasDrift())->toBeTrue()
        ->and($report->mutationCount(SyncIntegrityMutationKind::Added))->toBe(1)
        ->and($report->mutationCount(SyncIntegrityMutationKind::Changed))->toBe(1)
        ->and($report->mutationCount(SyncIntegrityMutationKind::Restored))->toBe(1)
        ->and($report->mutationCount(SyncIntegrityMutationKind::Tombstoned))->toBe(1);
});

it('does not repeat tombstones that were already recorded', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $tombstoned = $attestor->attest($scope, '42', ['name' => 'Gone']);

    $report = (new FullSnapshotAuditor)->audit(
        $scope,
        2,
        [s817Stored($tombstoned, tombstoned: true)],
        [],
        new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
    );

    expect($report->hasDrift())->toBeFalse()
        ->and($report->mutations)->toBe([]);
});

it('rejects duplicate identities mixed scopes and mixed key versions', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $otherScope = new SyncIntegrityScope('tenant:secondary', 'clients');
    $snapshot = $attestor->attest($scope, '42', ['name' => 'A']);
    $other = $attestor->attest($otherScope, '42', ['name' => 'A']);
    $oldKey = $attestor->attest($scope, '42', ['name' => 'A'], 1);
    $auditor = new FullSnapshotAuditor;
    $completedAt = new DateTimeImmutable('2026-08-26T10:00:00+00:00');

    expect(fn () => $auditor->audit($scope, 2, [], [$snapshot, $snapshot], $completedAt))
        ->toThrow(InvalidArgumentException::class, 'duplicate observed identity')
        ->and(fn () => $auditor->audit($scope, 2, [], [$other], $completedAt))
        ->toThrow(InvalidArgumentException::class, 'cannot mix scopes')
        ->and(fn () => $auditor->audit($scope, 2, [], [$oldKey], $completedAt))
        ->toThrow(InvalidArgumentException::class, 'cannot mix HMAC key versions');
});

it('rejects unbounded full-audit inputs before indexing them', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $snapshot = $attestor->attest($scope, '42', ['name' => 'A']);

    expect(fn (): FullSnapshotAuditReport => (new FullSnapshotAuditor)->audit(
        $scope,
        2,
        [],
        array_fill(0, 10_001, $snapshot),
        new DateTimeImmutable('2026-08-26T10:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'at most 10000 snapshots per side');
});

it('rejects incoherent full-audit reports', function (): void {
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $detectedAt = new DateTimeImmutable('2026-08-26T10:00:00+00:00');
    $identity = new SnapshotHmac(2, str_repeat('a', 64));
    $current = new SnapshotHmac(2, str_repeat('b', 64));
    $added = new SyncIntegrityMutation(
        SyncIntegrityMutationKind::Added,
        $identity,
        null,
        $current,
        $detectedAt,
    );

    expect(fn (): FullSnapshotAuditReport => new FullSnapshotAuditReport(
        $scope,
        1,
        0,
        1,
        0,
        [$added],
        $detectedAt,
    ))->toThrow(InvalidArgumentException::class, 'different HMAC key version')
        ->and(fn (): FullSnapshotAuditReport => new FullSnapshotAuditReport(
            $scope,
            2,
            0,
            1,
            0,
            [$added],
            new DateTimeImmutable('2026-08-26T09:59:59+00:00'),
        ))->toThrow(InvalidArgumentException::class, 'cannot postdate completion')
        ->and(fn (): FullSnapshotAuditReport => new FullSnapshotAuditReport(
            $scope,
            2,
            0,
            0,
            0,
            [$added],
            $detectedAt,
        ))->toThrow(InvalidArgumentException::class, 'observed count is inconsistent');
});

it('validates HMAC lifecycle and mutation invariants', function (): void {
    $attestor = s817Attestor();
    $scope = new SyncIntegrityScope('tenant:primary', 'clients');
    $snapshot = $attestor->attest($scope, '42', ['name' => 'A']);

    expect(fn (): SnapshotHmac => new SnapshotHmac(0, str_repeat('a', 64)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): SnapshotHmac => new SnapshotHmac(1, str_repeat('A', 64)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): StoredSnapshot => new StoredSnapshot(
            $snapshot,
            new DateTimeImmutable('2026-08-01T02:00:00+02:00'),
            new DateTimeImmutable('2026-08-02T00:00:00+00:00'),
        ))->toThrow(InvalidArgumentException::class, 'must use UTC')
        ->and(fn (): SyncIntegrityScope => new SyncIntegrityScope('tenant:primary', 'Clients'))
        ->toThrow(InvalidArgumentException::class, 'lane is invalid');
});
