<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Sync\DatabaseSyncCheckpointStore;
use Cieplik206\Fakturownia\Stateful\Sync\Contracts\SyncCheckpointStore;
use Illuminate\Database\Connection;

it('keeps the checkpoint contract provider-owned and its PostgreSQL adapter explicit', function (): void {
    $connectionType = (new ReflectionClass(DatabaseSyncCheckpointStore::class))
        ->getConstructor()?->getParameters()[0]->getType();

    expect(SyncCheckpointStore::class)->toBeInterface()
        ->and(DatabaseSyncCheckpointStore::class)->toImplement(SyncCheckpointStore::class)
        ->and($connectionType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($connectionType instanceof ReflectionNamedType ? $connectionType->getName() : null)
        ->toBe(Connection::class);
});

it('freezes the provider-owned checkpoint migration guards', function (): void {
    $path = dirname(__DIR__, 2).'/database/migrations/2026_08_26_000004_create_fakturownia_sync_checkpoints_table.php';
    $migration = file_get_contents($path);

    expect($migration)->not->toBeFalse()
        ->and($migration)->toContain("Schema::create('fakturownia_sync_checkpoints'")
        ->and($migration)->toContain('BEFORE UPDATE OR DELETE ON {{table}}')
        ->and($migration)->toContain('BEFORE TRUNCATE ON {{table}}')
        ->and($migration)->toContain('cursor cannot regress')
        ->and($migration)->toContain('lease cannot be replaced without fencing')
        ->and($migration)->not->toContain('foreignId')
        ->and($migration)->not->toContain('references(')
        ->and($migration)->not->toContain('CASCADE');
});

it('requires an ambient transaction before advancing a durable checkpoint', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Laravel/Sync/DatabaseSyncCheckpointStore.php',
    );

    expect($source)->not->toBeFalse()
        ->and($source)->toContain('transactionLevel() < 1')
        ->and($source)->toContain('durably stores the complete work unit')
        ->and($source)->toContain('lockForUpdate()')
        ->and($source)->toContain('useWritePdo()')
        ->and($source)->toContain('pg_advisory_xact_lock')
        ->and($source)->toContain('clock_timestamp()');
});
