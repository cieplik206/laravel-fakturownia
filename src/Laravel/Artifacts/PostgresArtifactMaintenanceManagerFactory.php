<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStoreFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

final readonly class PostgresArtifactMaintenanceManagerFactory
{
    public function __construct(
        private DatabaseManager $databases,
        private ConfigRepository $configuration,
        private ArtifactMaintenanceStoreFactory $storeFactory,
    ) {}

    public function make(): ArtifactMaintenanceManager
    {
        return ArtifactMaintenanceManager::fromLaravelConfiguration(
            $this->databases,
            $this->configuration,
            $this->storeFactory,
        );
    }
}
