<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function s61ArtifactMigration(): Migration
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_26_000001_create_fakturownia_artifacts_table.php';

    if (! $migration instanceof Migration) {
        throw new LogicException('The artifact migration must be a Laravel migration.');
    }

    return $migration;
}

it('uses the kernel database connection without importing kernel persistence types', function (): void {
    config()->set('integration-operations.database.connection', 'provider_operations');

    expect(s61ArtifactMigration()->getConnection())->toBe('provider_operations');

    config()->set('integration-operations.database.connection', null);

    expect(s61ArtifactMigration()->getConnection())->toBeNull();
});

it('fails closed when the shared database connection configuration has an invalid type', function (): void {
    config()->set('integration-operations.database.connection', ['not-a-connection']);

    expect(fn (): ?string => s61ArtifactMigration()->getConnection())
        ->toThrow(InvalidArgumentException::class);
});

it('loads the provider-owned artifact migration with logical references only', function (): void {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('fakturownia_artifacts'))->toBeTrue()
        ->and(Schema::hasTable('fakturownia_artifact_locks'))->toBeTrue()
        ->and(Schema::getColumnListing('fakturownia_artifact_locks'))->toBe([
            'key',
            'owner',
            'expiration',
        ])
        ->and(Schema::getForeignKeys('fakturownia_artifact_locks'))->toBe([]);

    expect(Schema::getColumnListing('fakturownia_artifacts'))->toBe([
        'id',
        'connection_key',
        'operation_id',
        'resource_id',
        'artifact_type',
        'revision_key_hmac',
        'source_snapshot_fingerprint_hmac',
        'source_ksef_operation_id',
        'source_gov_id_key_version',
        'source_gov_id_cipher',
        'source_gov_id_ciphertext',
        'source_gov_id_ciphertext_sha256',
        'disk',
        'storage_prefix',
        'content_address',
        'storage_key_version',
        'storage_key_cipher',
        'storage_key_ciphertext',
        'storage_key_ciphertext_sha256',
        'content_sha256',
        'mime_type',
        'size_bytes',
        'status',
        'created_at',
        'ready_at',
        'expires_at',
        'deleted_at',
    ])->and(Schema::getForeignKeys('fakturownia_artifacts'))->toBe([]);
});

it('indexes logical ownership, object lookup, retention, and one immutable revision', function (): void {
    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasIndex(
        'fakturownia_artifacts',
        'fakturownia_artifacts_resource_revision_unique',
        'unique',
    ))->toBeTrue()
        ->and(Schema::hasIndex(
            'fakturownia_artifacts',
            'fakturownia_artifacts_connection_operation_idx',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'fakturownia_artifacts',
            'fakturownia_artifacts_connection_ksef_operation_idx',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'fakturownia_artifacts',
            'fakturownia_artifacts_object_idx',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'fakturownia_artifacts',
            'fakturownia_artifacts_retention_idx',
        ))->toBeTrue();
});

it('does not allow two descriptors for the same provider resource revision', function (): void {
    Artisan::call('migrate', ['--force' => true]);

    $descriptor = [
        'id' => '01K3N000000000000000000001',
        'connection_key' => 'tenant:default',
        'operation_id' => '01K3N000000000000000000002',
        'resource_id' => '01K3N000000000000000000003',
        'artifact_type' => 'invoice_pdf',
        'revision_key_hmac' => str_repeat('a', 64),
        'source_snapshot_fingerprint_hmac' => str_repeat('b', 64),
        'source_ksef_operation_id' => null,
        'source_gov_id_key_version' => null,
        'source_gov_id_cipher' => null,
        'source_gov_id_ciphertext' => null,
        'source_gov_id_ciphertext_sha256' => null,
        'disk' => 'shared-artifacts',
        'storage_prefix' => 'fakturownia/finalized',
        'content_address' => 'sha256:'.str_repeat('c', 64),
        'storage_key_version' => 1,
        'storage_key_cipher' => 'AES-256-GCM',
        'storage_key_ciphertext' => 'encrypted-storage-key',
        'storage_key_ciphertext_sha256' => hash('sha256', 'encrypted-storage-key'),
        'content_sha256' => str_repeat('c', 64),
        'mime_type' => 'application/pdf',
        'size_bytes' => 1_024,
        'status' => 'ready',
        'created_at' => '2026-08-26 01:00:00.000000+00:00',
        'ready_at' => '2026-08-26 01:00:01.000000+00:00',
        'expires_at' => null,
        'deleted_at' => null,
    ];

    DB::table('fakturownia_artifacts')->insert($descriptor);

    expect(fn (): bool => DB::table('fakturownia_artifacts')->insert([
        ...$descriptor,
        'id' => '01K3N000000000000000000004',
        'operation_id' => '01K3N000000000000000000005',
    ]))->toThrow(QueryException::class);
});
