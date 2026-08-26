<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Laravel\ConfigConnectionResolver;
use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticProviderExtensions;
use Cieplik206\Fakturownia\Stateful\Exceptions\ConnectionConfigurationInvalid;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\FakturowniaOperations;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
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
