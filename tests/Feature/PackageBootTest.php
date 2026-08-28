<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Laravel\Artifacts\PostgresArtifactMaintenanceManagerFactory;
use Cieplik206\Fakturownia\Laravel\ConfigConnectionResolver;
use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactMaintenanceReport;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\ArtifactPurgePermitVerifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticProviderExtensions;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationInvalid;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\FakturowniaOperations;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\AuthoritativeIssueProformaOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\Contracts\IssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\DisabledIssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationFactory;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('discovers and boots without resolving credentials', function (): void {
    expect($this->app->bound(FakturowniaManager::class))->toBeTrue()
        ->and($this->app->bound(ClientFactory::class))->toBeTrue()
        ->and($this->app->bound(ConnectionResolver::class))->toBeTrue()
        ->and($this->app->bound(OperationQuery::class))->toBeTrue()
        ->and($this->app->make(ConnectionResolver::class))->toBeInstanceOf(ConfigConnectionResolver::class)
        ->and($this->app->bound(FakturowniaDiagnosticProviderExtensions::class))->toBeTrue()
        ->and($this->app->resolved(FakturowniaDiagnosticProviderExtensions::class))->toBeFalse()
        ->and($this->app->make(DefinitionRegistry::class)->find(
            FakturowniaDiagnosticDefinitionProvider::provider(),
            new OperationType('fakturownia.diagnostic.echo'),
            1,
        ))->not->toBeNull();
});

it('registers the proforma runtime with a fail-closed default transport', function (): void {
    $operationType = new OperationType(IssueProformaOperationFactory::OperationType);

    expect($this->app->make(DefinitionRegistry::class)->find(
        IssueProformaOperationDefinitionProvider::provider(),
        $operationType,
        1,
    ))->not->toBeNull()
        ->and($this->app->make(AuthoritativeDefinitionRegistry::class)->find(
            AuthoritativeIssueProformaOperationDefinitionProvider::provider(),
            $operationType,
            1,
        ))->not->toBeNull()
        ->and($this->app->make(IssueProformaTransport::class))
        ->toBeInstanceOf(DisabledIssueProformaTransport::class);
});

it('injects the shared scoped operation query into resolved connections', function (): void {
    config()->set('fakturownia.connections.testing', [
        'deployment_stage' => 'non_production',
        'base_url' => 'https://testing.fakturownia.pl',
        'allowed_hosts' => ['testing.fakturownia.pl'],
        'token' => 'test-token',
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 20,
    ]);

    $operations = $this->app
        ->make(FakturowniaManager::class)
        ->connection(new ConnectionKey('testing'))
        ->operations();

    expect($operations::class)->toBe(FakturowniaOperations::class);
});

it('defers invalid configuration failure until a connection is used', function (): void {
    $manager = $this->app->make(FakturowniaManager::class);

    expect(fn () => $manager->connection(new ConnectionKey('default')))
        ->toThrow(ConnectionConfigurationInvalid::class);
});

it('registers an explicit install command without executing migrations', function (): void {
    Http::fake();
    Queue::fake();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $exitCode = Artisan::call('fakturownia:install', ['--force' => true]);

    expect($exitCode)->toBe(0)
        ->and($queries)->toBe(0)
        ->and(is_dir(dirname(__DIR__, 2).'/database/migrations'))->toBeTrue();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('propagates configuration publisher failures from the install command', function (): void {
    $this->app->instance(ConfigurationPublisher::class, new class implements ConfigurationPublisher
    {
        public function publish(bool $force): int
        {
            return 17;
        }
    });

    expect(Artisan::call('fakturownia:install'))->toBe(1);
});

it('registers fail-closed provider and artifact maintenance diagnostics without leaking credentials', function (): void {
    config()->set('fakturownia.connections.default.token', 'doctor-secret-token');
    Http::fake();
    Queue::fake();

    $doctorExitCode = Artisan::call('fakturownia:doctor');
    $doctorOutput = Artisan::output();
    $maintenanceExitCode = Artisan::call('fakturownia:artifacts:maintain', [
        'action' => 'prune',
    ]);
    $maintenanceOutput = Artisan::output();

    expect($doctorExitCode)->toBe(1)
        ->and($doctorOutput)->toContain('6 provider operation definitions and 5 authoritative definitions are frozen')
        ->and($doctorOutput)->toContain('capability-aware artifact maintenance store is not bound')
        ->and($doctorOutput)->not->toContain('doctor-secret-token')
        ->and($maintenanceExitCode)->toBe(2)
        ->and($maintenanceOutput)->toContain('requires the explicit --force option')
        ->and($maintenanceOutput)->not->toContain('doctor-secret-token');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('emits a bounded artifact maintenance cursor and fails until the batch is complete', function (): void {
    $continuationCursor = '01K3NTQ7V8R2K9X4M6P1C5D0HF';

    $this->app->instance(ArtifactMaintenanceStoreFactory::class, new class implements ArtifactMaintenanceStoreFactory
    {
        public function make(ArtifactPurgePermitVerifier $purgePermitVerifier): ArtifactMaintenanceStore
        {
            throw new LogicException('The command factory substitution must prevent store resolution.');
        }
    });
    $this->app->instance(PostgresArtifactMaintenanceManagerFactory::class, new class($continuationCursor)
    {
        public function __construct(private readonly string $continuationCursor) {}

        public function make(): object
        {
            return new class($this->continuationCursor)
            {
                public function __construct(private readonly string $continuationCursor) {}

                public function prune(?string $after): ArtifactMaintenanceReport
                {
                    expect($after)->toBeNull();

                    return new ArtifactMaintenanceReport(100, 100, 100, 0, [], $this->continuationCursor);
                }
            };
        }
    });

    $exitCode = Artisan::call('fakturownia:artifacts:maintain', [
        'action' => 'prune',
        '--force' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('bounded batch is incomplete')
        ->and($output)->toContain('continuation_cursor: '.$continuationCursor);
});
