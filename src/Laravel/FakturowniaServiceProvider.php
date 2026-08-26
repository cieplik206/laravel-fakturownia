<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel;

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Laravel\Console\InstallFakturowniaCommand;
use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Cieplik206\Fakturownia\Laravel\Ksef\DatabaseKsefStateProjectionStore;
use Cieplik206\Fakturownia\Laravel\Ksef\DispatchInvoiceKsefAccepted;
use Cieplik206\Fakturownia\Laravel\Reconciliation\ConfigInvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Laravel\Resources\DatabaseInvoiceResourceStore;
use Cieplik206\Fakturownia\Laravel\Resources\SodiumInvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticProviderExtensions;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\AuthoritativeIssueInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\Contracts\IssueInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\DisabledIssueInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\AuthoritativeIssueInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts\InvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\IssueInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\AuthoritativeEnsureAcceptedRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefSendTransport;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateProjectionStore;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\DisabledKsefSendTransport;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedObservationProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedObservationProjector;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationFactory;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationHandler;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPollingStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResultCodec;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\FakturowniaKsefInvoiceObservationReader;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceReader;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\IntegrationOperations;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class FakturowniaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fakturownia.php', 'fakturownia');

        $this->app->singleton(ClientFactory::class, DefaultClientFactory::class);
        $this->app->singleton(ConnectionResolver::class, ConfigConnectionResolver::class);
        $this->app->singleton(ConfigurationPublisher::class, ArtisanConfigurationPublisher::class);
        $this->app->singleton(
            FakturowniaManager::class,
            static fn (Application $app): FakturowniaManager => new FakturowniaManager(
                $app->make(ConnectionResolver::class),
                $app->make(ClientFactory::class),
                new DeferredOperationQuery($app),
                $app->make(KsefStateReader::class),
            ),
        );
        $this->app->singleton(FakturowniaDiagnosticProviderExtensions::class);
        $this->app->singleton(IssueInvoicePayloadCodec::class);
        $this->app->singleton(IssueInvoiceOperationHandler::class);
        $this->app->singleton(IssueInvoiceFailureClassifier::class);
        $this->app->singleton(AuthoritativeIssueInvoiceFailureClassifier::class);
        $this->app->singleton(IssueInvoiceRetryPolicy::class);
        $this->app->singleton(AuthoritativeIssueInvoiceRetryPolicy::class);
        $this->app->singleton(ConfigInvoiceReconciliationConfiguration::class);
        $this->app->alias(
            ConfigInvoiceReconciliationConfiguration::class,
            InvoiceReconciliationConfiguration::class,
        );
        $this->app->singleton(IssueInvoiceReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeIssueInvoiceReconciliationStrategy::class);
        $this->app->singleton(IssueInvoiceResultCodec::class);
        $this->app->singleton(IssueInvoiceOutcomeProjector::class);
        $this->app->singleton(IssueInvoiceOutcomeProjectionPlanner::class);
        $this->app->singleton(IssueInvoiceResourceProjectionMapper::class);
        $this->app->singleton(DisabledIssueInvoiceTransport::class);
        $this->app->alias(DisabledIssueInvoiceTransport::class, IssueInvoiceTransport::class);
        $this->app->singleton(SodiumInvoiceResourceSnapshotProtector::class);
        $this->app->alias(
            SodiumInvoiceResourceSnapshotProtector::class,
            InvoiceResourceSnapshotProtector::class,
        );
        $this->app->singleton(DatabaseInvoiceResourceStore::class);
        $this->app->alias(DatabaseInvoiceResourceStore::class, InvoiceResourceProjectionStore::class);
        $this->app->alias(DatabaseInvoiceResourceStore::class, InvoiceResourceReader::class);

        $this->app->singleton(EnsureAcceptedPayloadCodec::class);
        $this->app->singleton(EnsureAcceptedOperationFactory::class);
        $this->app->singleton(EnsureAcceptedOperationHandler::class);
        $this->app->singleton(EnsureAcceptedFailureClassifier::class);
        $this->app->singleton(AuthoritativeEnsureAcceptedFailureClassifier::class);
        $this->app->singleton(EnsureAcceptedRetryPolicy::class);
        $this->app->singleton(AuthoritativeEnsureAcceptedRetryPolicy::class);
        $this->app->singleton(EnsureAcceptedReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeEnsureAcceptedReconciliationStrategy::class);
        $this->app->singleton(EnsureAcceptedPollingStrategy::class);
        $this->app->singleton(EnsureAcceptedResultCodec::class);
        $this->app->singleton(EnsureAcceptedObservationProjectionPlanner::class);
        $this->app->singleton(EnsureAcceptedOutcomeProjectionPlanner::class);
        $this->app->singleton(EnsureAcceptedObservationProjector::class);
        $this->app->singleton(EnsureAcceptedOutcomeProjector::class);
        $this->app->singleton(FakturowniaKsefInvoiceObservationReader::class);
        $this->app->alias(FakturowniaKsefInvoiceObservationReader::class, KsefInvoiceObservationReader::class);
        $this->app->singleton(DisabledKsefSendTransport::class);
        $this->app->alias(DisabledKsefSendTransport::class, KsefSendTransport::class);
        $this->app->singleton(DatabaseKsefStateProjectionStore::class);
        $this->app->alias(DatabaseKsefStateProjectionStore::class, KsefStateProjectionStore::class);
        $this->app->alias(DatabaseKsefStateProjectionStore::class, KsefStateReader::class);
        $this->app->singleton(DispatchInvoiceKsefAccepted::class);
    }

    public function boot(IntegrationOperations $operations): void
    {
        $operations->registerProvider(FakturowniaDiagnosticDefinitionProvider::class);
        $operations->registerProvider(IssueInvoiceOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeIssueInvoiceOperationDefinitionProvider::class);
        $operations->registerProvider(EnsureAcceptedOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeEnsureAcceptedOperationDefinitionProvider::class);

        $this->app->make(Dispatcher::class)->listen(
            OperationTerminalized::class,
            [DispatchInvoiceKsefAccepted::class, 'handle'],
        );

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../config/fakturownia.php' => config_path('fakturownia.php'),
        ], 'fakturownia-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallFakturowniaCommand::class]);
        }
    }
}
