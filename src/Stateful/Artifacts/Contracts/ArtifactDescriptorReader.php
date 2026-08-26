<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

interface ArtifactDescriptorReader
{
    public function find(ConnectionKey $connectionKey, ArtifactId $artifactId): ?ArtifactDescriptor;

    public function findByOperation(ConnectionKey $connectionKey, OperationId $operationId): ?ArtifactDescriptor;

    public function findByRevision(
        ConnectionKey $connectionKey,
        InvoiceResourceId $resourceId,
        ArtifactType $type,
        string $revisionKeyHmac,
    ): ?ArtifactDescriptor;
}
