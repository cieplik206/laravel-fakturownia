<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel;

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Laravel\Artifacts\AesGcmArtifactMetadataProtector;
use Cieplik206\Fakturownia\Laravel\Artifacts\CacheArtifactAddressLock;
use Cieplik206\Fakturownia\Laravel\Artifacts\ConfigInvoicePdfConfiguration;
use Cieplik206\Fakturownia\Laravel\Artifacts\DatabaseArtifactStore;
use Cieplik206\Fakturownia\Laravel\Artifacts\DispatchInvoicePdfReady;
use Cieplik206\Fakturownia\Laravel\Artifacts\FakturowniaInvoicePdfSourceReader;
use Cieplik206\Fakturownia\Laravel\Artifacts\FilesystemContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Laravel\Console\DoctorFakturowniaCommand;
use Cieplik206\Fakturownia\Laravel\Console\InstallFakturowniaCommand;
use Cieplik206\Fakturownia\Laravel\Console\MaintainFakturowniaArtifactsCommand;
use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Cieplik206\Fakturownia\Laravel\Ksef\DatabaseKsefStateProjectionStore;
use Cieplik206\Fakturownia\Laravel\Ksef\DispatchInvoiceKsefAccepted;
use Cieplik206\Fakturownia\Laravel\Reconciliation\ConfigInvoiceReconciliationConfiguration;
use Cieplik206\Fakturownia\Laravel\Resources\DatabaseInvoiceResourceStore;
use Cieplik206\Fakturownia\Laravel\Resources\SodiumInvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactDescriptorReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactMetadataProtector;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactProjectionStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfConfiguration;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationHandler;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResultCodec;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfStager;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\AuthoritativeIssueCorrectionRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\Contracts\IssueCorrectionTransport;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\DisabledIssueCorrectionTransport;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationFactory;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationHandler;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResultCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\AuthoritativeDeleteCostInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\Contracts\DeleteCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DeleteCostInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Costs\Delete\DisabledDeleteCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\AuthoritativeIssueCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\Contracts\IssueCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\DisabledIssueCostInvoiceTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoiceOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Costs\Operations\IssueCostInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Status\AuthoritativeChangeCostInvoiceStatusOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Status\AuthoritativeChangeCostInvoiceStatusReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationFactory;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOperationHandler;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Costs\Status\ChangeCostInvoiceStatusResultCodec;
use Cieplik206\Fakturownia\Stateful\Costs\Status\Contracts\ChangeCostInvoiceStatusTransport;
use Cieplik206\Fakturownia\Stateful\Costs\Status\DisabledChangeCostInvoiceStatusTransport;
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
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationFactory;
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
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\AuthoritativeIssueProformaOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\Contracts\IssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\DisabledIssueProformaTransport;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationFactory;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOperationHandler;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOutcomeProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaOutcomeProjector;
use Cieplik206\Fakturownia\Stateful\Proformas\Operations\IssueProformaPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaRequestPayloadMapper;
use Cieplik206\Fakturownia\Stateful\Proformas\Reconciliation\AuthoritativeIssueProformaReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Proformas\Reconciliation\IssueProformaReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceProjectionStore;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceReader;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshotProtector;
use Cieplik206\Fakturownia\Stateful\Resources\IssueCostInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Resources\IssueInvoiceResourceProjectionMapper;
use Cieplik206\Fakturownia\Stateful\Resources\IssueProformaResourceProjectionMapper;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\IntegrationOperations;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
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
                $app->make(ArtifactDescriptorReader::class),
                $app->make(ContentAddressedArtifactStore::class),
            ),
        );
        $this->app->singleton(FakturowniaDiagnosticProviderExtensions::class);
        $this->app->singleton(IssueInvoicePayloadCodec::class);
        $this->app->singleton(IssueInvoiceOperationFactory::class);
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

        $this->app->singleton(IssueCostInvoicePayloadCodec::class);
        $this->app->singleton(IssueCostInvoiceOperationFactory::class);
        $this->app->singleton(IssueCostInvoiceOperationHandler::class);
        $this->app->singleton(IssueCostInvoiceOutcomeProjector::class);
        $this->app->singleton(IssueCostInvoiceOutcomeProjectionPlanner::class);
        $this->app->singleton(IssueCostInvoiceResourceProjectionMapper::class);
        $this->app->singleton(DisabledIssueCostInvoiceTransport::class);
        $this->app->alias(DisabledIssueCostInvoiceTransport::class, IssueCostInvoiceTransport::class);

        $this->app->singleton(ChangeCostInvoiceStatusPayloadCodec::class);
        $this->app->singleton(ChangeCostInvoiceStatusOperationFactory::class);
        $this->app->singleton(ChangeCostInvoiceStatusOperationHandler::class);
        $this->app->singleton(ChangeCostInvoiceStatusReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeChangeCostInvoiceStatusReconciliationStrategy::class);
        $this->app->singleton(ChangeCostInvoiceStatusResultCodec::class);
        $this->app->singleton(ChangeCostInvoiceStatusOutcomeProjector::class);
        $this->app->singleton(ChangeCostInvoiceStatusOutcomeProjectionPlanner::class);
        $this->app->singleton(DisabledChangeCostInvoiceStatusTransport::class);
        $this->app->alias(
            DisabledChangeCostInvoiceStatusTransport::class,
            ChangeCostInvoiceStatusTransport::class,
        );

        $this->app->singleton(DeleteCostInvoicePayloadCodec::class);
        $this->app->singleton(DeleteCostInvoiceOperationFactory::class);
        $this->app->singleton(DeleteCostInvoiceOperationHandler::class);
        $this->app->singleton(DeleteCostInvoiceFailureClassifier::class);
        $this->app->singleton(AuthoritativeDeleteCostInvoiceFailureClassifier::class);
        $this->app->singleton(DeleteCostInvoiceRetryPolicy::class);
        $this->app->singleton(AuthoritativeDeleteCostInvoiceRetryPolicy::class);
        $this->app->singleton(DeleteCostInvoiceReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeDeleteCostInvoiceReconciliationStrategy::class);
        $this->app->singleton(DeleteCostInvoiceResultCodec::class);
        $this->app->singleton(DeleteCostInvoiceOutcomeProjector::class);
        $this->app->singleton(DeleteCostInvoiceOutcomeProjectionPlanner::class);
        $this->app->singleton(DisabledDeleteCostInvoiceTransport::class);
        $this->app->alias(DisabledDeleteCostInvoiceTransport::class, DeleteCostInvoiceTransport::class);

        $this->app->singleton(IssueProformaPayloadCodec::class);
        $this->app->singleton(IssueProformaOperationFactory::class);
        $this->app->singleton(IssueProformaOperationHandler::class);
        $this->app->singleton(IssueProformaReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeIssueProformaReconciliationStrategy::class);
        $this->app->singleton(IssueProformaOutcomeProjector::class);
        $this->app->singleton(IssueProformaOutcomeProjectionPlanner::class);
        $this->app->singleton(ProformaRequestPayloadMapper::class);
        $this->app->singleton(IssueProformaResourceProjectionMapper::class);
        $this->app->singleton(DisabledIssueProformaTransport::class);
        $this->app->alias(DisabledIssueProformaTransport::class, IssueProformaTransport::class);
        $this->app->singleton(SodiumInvoiceResourceSnapshotProtector::class);
        $this->app->alias(
            SodiumInvoiceResourceSnapshotProtector::class,
            InvoiceResourceSnapshotProtector::class,
        );
        $this->app->singleton(DatabaseInvoiceResourceStore::class);
        $this->app->alias(DatabaseInvoiceResourceStore::class, InvoiceResourceProjectionStore::class);
        $this->app->alias(DatabaseInvoiceResourceStore::class, InvoiceResourceReader::class);

        $this->app->singleton(IssueCorrectionPayloadCodec::class);
        $this->app->singleton(IssueCorrectionOperationFactory::class);
        $this->app->singleton(IssueCorrectionOperationHandler::class);
        $this->app->singleton(IssueCorrectionFailureClassifier::class);
        $this->app->singleton(AuthoritativeIssueCorrectionFailureClassifier::class);
        $this->app->singleton(IssueCorrectionRetryPolicy::class);
        $this->app->singleton(AuthoritativeIssueCorrectionRetryPolicy::class);
        $this->app->singleton(IssueCorrectionReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeIssueCorrectionReconciliationStrategy::class);
        $this->app->singleton(IssueCorrectionResultCodec::class);
        $this->app->singleton(IssueCorrectionOutcomeProjector::class);
        $this->app->singleton(IssueCorrectionOutcomeProjectionPlanner::class);
        $this->app->singleton(CorrectionResourceProjectionMapper::class);
        $this->app->singleton(DisabledIssueCorrectionTransport::class);
        $this->app->alias(DisabledIssueCorrectionTransport::class, IssueCorrectionTransport::class);

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

        $this->app->singleton(DownloadInvoicePdfPayloadCodec::class);
        $this->app->singleton(ConfigInvoicePdfConfiguration::class);
        $this->app->alias(ConfigInvoicePdfConfiguration::class, InvoicePdfConfiguration::class);
        $this->app->singleton(DownloadInvoicePdfOperationFactory::class);
        $this->app->singleton(InvoicePdfStager::class);
        $this->app->singleton(DownloadInvoicePdfOperationHandler::class);
        $this->app->singleton(DownloadInvoicePdfFailureClassifier::class);
        $this->app->singleton(AuthoritativeDownloadInvoicePdfFailureClassifier::class);
        $this->app->singleton(DownloadInvoicePdfRetryPolicy::class);
        $this->app->singleton(AuthoritativeDownloadInvoicePdfRetryPolicy::class);
        $this->app->singleton(DownloadInvoicePdfReconciliationStrategy::class);
        $this->app->singleton(AuthoritativeDownloadInvoicePdfReconciliationStrategy::class);
        $this->app->singleton(InvoicePdfReadyResultCodec::class);
        $this->app->singleton(InvoicePdfOutcomeProjectionPlanner::class);
        $this->app->singleton(InvoicePdfOutcomeProjector::class);
        $this->app->singleton(FakturowniaInvoicePdfSourceReader::class);
        $this->app->alias(FakturowniaInvoicePdfSourceReader::class, InvoicePdfSourceReader::class);
        $this->app->singleton(ArtifactAddressLock::class, static function (Application $app): ArtifactAddressLock {
            $schema = $app->make('config')->get('fakturownia.artifacts.lock_schema');

            if (! is_string($schema) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
                throw new \InvalidArgumentException('The artifact lock schema is invalid.');
            }

            return new CacheArtifactAddressLock(
                $app->make(KernelDatabase::class)->connection(),
                $schema.'.fakturownia_artifact_locks',
            );
        });
        $this->app->singleton(FilesystemContentAddressedArtifactStore::class);
        $this->app->alias(FilesystemContentAddressedArtifactStore::class, ContentAddressedArtifactStore::class);
        $this->app->singleton(AesGcmArtifactMetadataProtector::class);
        $this->app->alias(AesGcmArtifactMetadataProtector::class, ArtifactMetadataProtector::class);
        $this->app->singleton(DatabaseArtifactStore::class);
        $this->app->alias(DatabaseArtifactStore::class, ArtifactProjectionStore::class);
        $this->app->alias(DatabaseArtifactStore::class, ArtifactDescriptorReader::class);
        $this->app->singleton(DispatchInvoicePdfReady::class);
    }

    public function boot(IntegrationOperations $operations): void
    {
        $operations->registerProvider(FakturowniaDiagnosticDefinitionProvider::class);
        $operations->registerProvider(IssueInvoiceOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeIssueInvoiceOperationDefinitionProvider::class);
        $operations->registerProvider(IssueCostInvoiceOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeIssueCostInvoiceOperationDefinitionProvider::class);
        $operations->registerProvider(ChangeCostInvoiceStatusOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(
            AuthoritativeChangeCostInvoiceStatusOperationDefinitionProvider::class,
        );
        $operations->registerProvider(DeleteCostInvoiceOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(
            AuthoritativeDeleteCostInvoiceOperationDefinitionProvider::class,
        );
        $operations->registerProvider(IssueProformaOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeIssueProformaOperationDefinitionProvider::class);
        $operations->registerProvider(IssueCorrectionOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeIssueCorrectionOperationDefinitionProvider::class);
        $operations->registerProvider(EnsureAcceptedOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeEnsureAcceptedOperationDefinitionProvider::class);
        $operations->registerProvider(DownloadInvoicePdfOperationDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(AuthoritativeDownloadInvoicePdfOperationDefinitionProvider::class);

        $this->app->make(Dispatcher::class)->listen(
            OperationTerminalized::class,
            [DispatchInvoiceKsefAccepted::class, 'handle'],
        );
        $this->app->make(Dispatcher::class)->listen(
            OperationTerminalized::class,
            [DispatchInvoicePdfReady::class, 'handle'],
        );

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../config/fakturownia.php' => config_path('fakturownia.php'),
        ], 'fakturownia-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorFakturowniaCommand::class,
                InstallFakturowniaCommand::class,
                MaintainFakturowniaArtifactsCommand::class,
            ]);
        }
    }
}
