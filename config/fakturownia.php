<?php

declare(strict_types=1);

$allowedHosts = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('FAKTUROWNIA_ALLOWED_HOSTS', '')),
)));

return [
    'connections' => [
        'default' => [
            'deployment_stage' => env('FAKTUROWNIA_DEPLOYMENT_STAGE', 'production'),
            'base_url' => env('FAKTUROWNIA_BASE_URL', ''),
            'allowed_hosts' => $allowedHosts,
            'token' => env('FAKTUROWNIA_TOKEN', ''),
            'connect_timeout_seconds' => (int) env('FAKTUROWNIA_CONNECT_TIMEOUT_SECONDS', 10),
            'request_timeout_seconds' => (int) env('FAKTUROWNIA_REQUEST_TIMEOUT_SECONDS', 30),
        ],
    ],

    'artifacts' => [
        'connection' => env('FAKTUROWNIA_ARTIFACT_CONNECTION', 'default'),
        'disk' => env('FAKTUROWNIA_ARTIFACT_DISK', ''),
        'prefix' => env('FAKTUROWNIA_ARTIFACT_PREFIX', 'fakturownia'),
        'database_schema' => env('FAKTUROWNIA_ARTIFACT_DATABASE_SCHEMA', 'public'),
        'lock_schema' => env('FAKTUROWNIA_ARTIFACT_LOCK_SCHEMA', 'public'),
        'storage_topology' => env('FAKTUROWNIA_ARTIFACT_STORAGE_TOPOLOGY', 'unverified'),
        'retention_days' => (int) env('FAKTUROWNIA_ARTIFACT_RETENTION_DAYS', 90),
        'orphan_retention_hours' => (int) env('FAKTUROWNIA_ARTIFACT_ORPHAN_RETENTION_HOURS', 24),
        'maintenance_batch_size' => (int) env('FAKTUROWNIA_ARTIFACT_MAINTENANCE_BATCH_SIZE', 100),
        'require_shared_storage_in_production' => (bool) env('FAKTUROWNIA_ARTIFACT_REQUIRE_SHARED_STORAGE', true),
        'max_pdf_bytes' => (int) env('FAKTUROWNIA_ARTIFACT_MAX_PDF_BYTES', 20 * 1024 * 1024),
    ],

    'reconciliation' => [
        'visibility_window_seconds' => (int) env('FAKTUROWNIA_RECONCILIATION_VISIBILITY_SECONDS', 300),
        'required_absent_confirmations' => (int) env('FAKTUROWNIA_RECONCILIATION_ABSENT_CONFIRMATIONS', 2),
        'maximum_candidates_per_scan' => (int) env('FAKTUROWNIA_RECONCILIATION_MAX_CANDIDATES', 100),
        'minimum_absent_confirmation_interval_seconds' => (int) env(
            'FAKTUROWNIA_RECONCILIATION_ABSENT_INTERVAL_SECONDS',
            120,
        ),
        'maximum_remote_clock_skew_seconds' => (int) env('FAKTUROWNIA_RECONCILIATION_CLOCK_SKEW_SECONDS', 60),
    ],

    'resources' => [
        'encryption' => [
            'active_version' => (int) env('FAKTUROWNIA_RESOURCE_ENCRYPTION_ACTIVE_VERSION', 1),
            'keys' => [
                1 => env('FAKTUROWNIA_RESOURCE_ENCRYPTION_KEY_V1', ''),
            ],
        ],
    ],
];
