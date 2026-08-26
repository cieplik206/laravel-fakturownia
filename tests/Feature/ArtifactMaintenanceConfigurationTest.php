<?php

declare(strict_types=1);

it('publishes fail-closed artifact maintenance defaults', function (): void {
    $configuration = require dirname(__DIR__, 2).'/config/fakturownia.php';

    expect($configuration['artifacts'] ?? null)->toMatchArray([
        'connection' => 'default',
        'disk' => '',
        'prefix' => 'fakturownia',
        'database_schema' => 'public',
        'lock_schema' => 'public',
        'storage_topology' => 'unverified',
        'retention_days' => 90,
        'orphan_retention_hours' => 24,
        'maintenance_batch_size' => 100,
        'require_shared_storage_in_production' => true,
        'max_pdf_bytes' => 20 * 1024 * 1024,
    ]);
});
