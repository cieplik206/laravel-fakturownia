<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Console;

use Cieplik206\Fakturownia\Laravel\Artifacts\PostgresArtifactMaintenanceManagerFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Diagnostics\FakturowniaDiagnosticDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Configuration;
use Illuminate\Contracts\Container\Container;
use Throwable;

/** @internal */
final class DoctorFakturowniaCommand extends Command
{
    protected $signature = 'fakturownia:doctor';

    protected $description = 'Verify Fakturownia provider configuration without revealing credentials or document data';

    public function __construct(
        private readonly Configuration $configuration,
        private readonly Container $container,
        private readonly ConnectionResolver $connections,
        private readonly DefinitionRegistry $definitions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = [
            $this->connectionCheck(),
            $this->definitionCheck(),
            $this->artifactMaintenanceCheck(),
        ];

        $this->table(['check', 'status', 'detail'], $checks);

        foreach ($checks as $check) {
            if ($check[1] === 'failed') {
                $this->components->error('Fakturownia doctor found blocking configuration or infrastructure errors.');

                return self::FAILURE;
            }
        }

        $this->components->info('Fakturownia doctor completed without blocking errors.');

        return self::SUCCESS;
    }

    /** @return array{string, string, string} */
    private function connectionCheck(): array
    {
        $connections = $this->configuration->get('fakturownia.connections');

        if (! is_array($connections) || $connections === []) {
            return ['connections', 'failed', 'No provider connection is configured.'];
        }

        try {
            foreach (array_keys($connections) as $connectionKey) {
                if (! is_string($connectionKey)) {
                    throw new \LogicException('Connection keys must be strings.');
                }

                $this->connections->resolve(new ConnectionKey($connectionKey));
            }
        } catch (Throwable) {
            return ['connections', 'failed', 'A provider connection is invalid; sensitive details were withheld.'];
        }

        return ['connections', 'ok', sprintf('%d provider connection(s) passed local validation.', count($connections))];
    }

    /** @return array{string, string, string} */
    private function definitionCheck(): array
    {
        $operationTypes = [
            FakturowniaDiagnosticDefinitionProvider::OperationType,
            IssueInvoiceOperationDefinitionProvider::OperationType,
            IssueCorrectionOperationDefinitionProvider::OperationType,
            EnsureAcceptedOperationDefinitionProvider::OperationType,
            DownloadInvoicePdfOperationDefinitionProvider::OperationType,
        ];
        $provider = FakturowniaDiagnosticDefinitionProvider::provider();

        foreach ($operationTypes as $operationType) {
            if ($this->definitions->find($provider, new OperationType($operationType), 1) === null) {
                return ['definitions', 'failed', 'A required provider operation definition is unavailable.'];
            }
        }

        return ['definitions', 'ok', sprintf('%d provider operation definitions are frozen.', count($operationTypes))];
    }

    /** @return array{string, string, string} */
    private function artifactMaintenanceCheck(): array
    {
        if (! $this->container->bound(ArtifactMaintenanceStoreFactory::class)) {
            return ['artifacts', 'failed', 'A capability-aware artifact maintenance store is not bound.'];
        }

        try {
            $report = $this->container->make(PostgresArtifactMaintenanceManagerFactory::class)->make()->doctor();
        } catch (Throwable) {
            return ['artifacts', 'failed', 'The artifact integrity check failed; sensitive details were withheld.'];
        }

        if (! $report->passes()) {
            return [
                'artifacts',
                'failed',
                sprintf('%d artifact integrity finding(s) require operator attention.', $report->totalFindings),
            ];
        }

        return ['artifacts', 'ok', sprintf('%d durable artifact descriptor(s) passed integrity checks.', $report->examined)];
    }
}
