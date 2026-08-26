<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Exceptions\ArtifactProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

final readonly class ArtifactProjectionPlan
{
    use RejectsNativeSerialization;

    public const string TargetId = 'fakturownia.invoice_artifact';

    public const int SchemaVersion = 1;

    public function __construct(
        public ArtifactId $artifactId,
        public ConnectionKey $connectionKey,
        public OperationId $operationId,
        public InvoiceResourceId $resourceId,
        public ArtifactType $type,
        public string $revisionKeyHmac,
        public string $sourceSnapshotFingerprintHmac,
        public ?OperationId $sourceKsefOperationId,
        public ?string $sourceGovernmentId,
        public ArtifactObjectDescriptor $object,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmac) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotFingerprintHmac) !== 1
            || ! $artifactId->equals(ArtifactId::fromRevisionHmac($revisionKeyHmac))
            || (($sourceKsefOperationId === null) !== ($sourceGovernmentId === null))) {
            throw new InvalidArgumentException('The artifact projection plan is inconsistent.');
        }
    }

    public function assertIdempotentWith(ArtifactDescriptor $artifact): void
    {
        if (! hash_equals($artifact->id, $this->artifactId->value)
            || ! hash_equals($artifact->connectionKey, $this->connectionKey->value)
            || ! hash_equals($artifact->operationId, $this->operationId->value)
            || ! hash_equals($artifact->resourceId, $this->resourceId->value)
            || $artifact->type !== $this->type
            || ! hash_equals($artifact->revisionKeyHmac, $this->revisionKeyHmac)
            || ! hash_equals($artifact->sourceSnapshotFingerprintHmac, $this->sourceSnapshotFingerprintHmac)
            || $artifact->sourceKsefOperationId !== $this->sourceKsefOperationId?->value
            || ! $artifact->object->contentAddress->equals($this->object->contentAddress)
            || ! hash_equals($artifact->object->disk, $this->object->disk)
            || ! hash_equals($artifact->object->mimeType, $this->object->mimeType)
            || $artifact->object->sizeBytes !== $this->object->sizeBytes
            || $artifact->status !== ArtifactStatus::Ready
            || $artifact->deletedAt !== null) {
            throw new ArtifactProjectionConflict('The artifact projection conflicts with a durable descriptor.');
        }
    }

    /** @return array{target: string, artifact: string, connection: string, operation: string, resource: string, revision: string, object: string} */
    public function __debugInfo(): array
    {
        return [
            'target' => self::TargetId,
            'artifact' => '[REDACTED]',
            'connection' => '[REDACTED]',
            'operation' => '[REDACTED]',
            'resource' => '[REDACTED]',
            'revision' => '[REDACTED]',
            'object' => '[REDACTED]',
        ];
    }
}
