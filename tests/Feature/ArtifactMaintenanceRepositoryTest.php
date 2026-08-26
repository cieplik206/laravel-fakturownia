<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\DatabaseArtifactMaintenanceRepository;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceScope;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactStorageTopology;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Illuminate\Database\SQLiteConnection;

function s64ReplicaTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE fakturownia_artifacts (
            id TEXT PRIMARY KEY,
            connection_key TEXT NOT NULL,
            disk TEXT NOT NULL,
            storage_prefix TEXT NOT NULL,
            content_address TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            status TEXT NOT NULL,
            ready_at TEXT NOT NULL,
            expires_at TEXT,
            deleted_at TEXT
        )
        SQL);
}

it('uses the authoritative write PDO for candidates and global reference proofs', function (): void {
    $writePdo = new PDO('sqlite::memory:');
    $laggingReadPdo = new PDO('sqlite::memory:');
    s64ReplicaTable($writePdo);
    s64ReplicaTable($laggingReadPdo);

    $connection = new SQLiteConnection($writePdo);
    $connection->setReadPdo($laggingReadPdo);
    $address = 'sha256:'.hash('sha256', 'authoritative-object');
    $base = [
        'disk' => 'shared-artifacts',
        'storage_prefix' => 'fakturownia/finalized',
        'content_address' => $address,
        'mime_type' => 'application/pdf',
        'size_bytes' => 20,
        'status' => 'ready',
        'ready_at' => '2026-05-28 11:00:00.000000+00:00',
        'expires_at' => '2026-08-26 11:00:00.000000+00:00',
        'deleted_at' => null,
    ];
    $connection->table('fakturownia_artifacts')->insert([
        ...$base,
        'id' => '01K3N000000000000000000101',
        'connection_key' => 'tenant:one',
    ]);
    $connection->table('fakturownia_artifacts')->insert([
        ...$base,
        'id' => '01K3N000000000000000000102',
        'connection_key' => 'tenant:other',
        'expires_at' => '2026-09-26 11:00:00.000000+00:00',
    ]);

    $scope = new ArtifactMaintenanceScope(
        'tenant:one',
        new ArtifactStorageNamespace('shared-artifacts', 'fakturownia/finalized'),
        DeploymentStage::Production,
        ArtifactStorageTopology::Shared,
    );
    $repository = new DatabaseArtifactMaintenanceRepository($connection);
    $now = new DateTimeImmutable('2026-08-26 12:00:00.123456+00:00');
    $page = $repository->expiredPage($scope, $now, null, 10);

    expect($page->records)->toHaveCount(1)
        ->and($repository->hasAnyActiveReference($scope, $page->records[0]->object->contentAddress))->toBeTrue()
        ->and($repository->hasOtherActiveReference($scope, $page->records[0]))->toBeTrue();
});
