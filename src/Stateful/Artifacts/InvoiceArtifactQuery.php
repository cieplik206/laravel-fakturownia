<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactDescriptorReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use RuntimeException;

final readonly class InvoiceArtifactQuery
{
    use RejectsNativeSerialization;

    public function __construct(
        private ConnectionKey $connectionKey,
        private ArtifactDescriptorReader $descriptors,
        private ContentAddressedArtifactStore $objects,
    ) {}

    public function find(ArtifactId $artifactId): ?ArtifactDescriptor
    {
        return $this->assertScope($this->descriptors->find($this->connectionKey, $artifactId));
    }

    public function findByOperation(OperationId $operationId): ?ArtifactDescriptor
    {
        return $this->assertScope($this->descriptors->findByOperation($this->connectionKey, $operationId));
    }

    public function findPdfByRevision(
        InvoiceResourceId $resourceId,
        string $revisionKeyHmac,
    ): ?ArtifactDescriptor {
        return $this->assertScope($this->descriptors->findByRevision(
            $this->connectionKey,
            $resourceId,
            ArtifactType::InvoicePdf,
            $revisionKeyHmac,
        ));
    }

    public function open(ArtifactId $artifactId): ArtifactContentStream
    {
        $artifact = $this->find($artifactId);

        if (! $artifact instanceof ArtifactDescriptor
            || $artifact->status !== ArtifactStatus::Ready
            || $artifact->deletedAt !== null) {
            throw new RuntimeException('The requested artifact is not ready.');
        }

        $actual = $this->objects->inspect($artifact->object->contentAddress);

        if (! $actual instanceof ArtifactObjectDescriptor
            || ! hash_equals($actual->disk, $artifact->object->disk)
            || ! $actual->contentAddress->equals($artifact->object->contentAddress)
            || ! hash_equals($actual->mimeType, $artifact->object->mimeType)
            || $actual->sizeBytes !== $artifact->object->sizeBytes) {
            throw new RuntimeException('The requested artifact object failed its integrity check.');
        }

        return $this->objects->open($artifact->object->contentAddress);
    }

    private function assertScope(?ArtifactDescriptor $artifact): ?ArtifactDescriptor
    {
        if ($artifact instanceof ArtifactDescriptor
            && ! hash_equals($artifact->connectionKey, $this->connectionKey->value)) {
            throw new RuntimeException('The artifact reader returned a cross-connection descriptor.');
        }

        return $artifact;
    }
}
