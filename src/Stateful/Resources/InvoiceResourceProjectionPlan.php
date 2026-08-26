<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Resources\Exceptions\InvoiceResourceProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class InvoiceResourceProjectionPlan
{
    use RejectsNativeSerialization;

    public const string TargetId = 'fakturownia.invoice_resource';

    public const int SchemaVersion = 1;

    public const int SnapshotSchemaVersion = 1;

    public function __construct(
        public InvoiceResourceId $resourceId,
        public ConnectionKey $connectionKey,
        public OperationId $operationId,
        public string $localReferenceType,
        public VersionedHmacDigest $localReferenceHmac,
        public IssueInvoiceResult $snapshot,
        public VersionedHmacDigest $snapshotFingerprint,
    ) {
        if (! $resourceId->equals(InvoiceResourceId::fromOperationId($operationId))
            || $localReferenceType !== InvoiceResource::LocalReferenceType
            || $localReferenceHmac->domain !== LookupHmacDomain::Intent
            || $snapshotFingerprint->domain !== LookupHmacDomain::Payload) {
            throw new InvalidArgumentException('Invoice resource projection plan is inconsistent.');
        }
    }

    public function assertIdempotentWith(InvoiceResource $resource): void
    {
        $sameLocalDigest = $resource->localReferenceHmac->keyVersion !== $this->localReferenceHmac->keyVersion
            || $resource->localReferenceHmac->equals($this->localReferenceHmac);
        $sameSnapshotFingerprint = $resource->snapshotFingerprint->keyVersion !== $this->snapshotFingerprint->keyVersion
            || $resource->snapshotFingerprint->equals($this->snapshotFingerprint);
        $sameSnapshot = (new IssueInvoiceResultCodec)->encode($resource->snapshot)
            ->equals((new IssueInvoiceResultCodec)->encode($this->snapshot));

        if (! $resource->id->equals($this->resourceId)
            || ! $resource->connectionKey->equals($this->connectionKey)
            || $resource->localReferenceType !== $this->localReferenceType
            || ! $sameLocalDigest
            || ! hash_equals($resource->remoteId, $this->snapshot->remoteId)
            || ! hash_equals($resource->remoteNumber, $this->snapshot->number)
            || ! $resource->createdByOperationId->equals($this->operationId)
            || ! $resource->lastOperationId->equals($this->operationId)
            || ! $sameSnapshotFingerprint
            || ! $sameSnapshot
            || $resource->deletedRemotelyAt !== null) {
            throw new InvoiceResourceProjectionConflict('Invoice resource projection conflicts with a durable mapping.');
        }
    }

    /** @return array{target: string, resource_type: string, connection: string, operation: string, remote_id: string, local_reference: string, snapshot: string} */
    public function __debugInfo(): array
    {
        return [
            'target' => self::TargetId,
            'resource_type' => InvoiceResource::ResourceType,
            'connection' => '[REDACTED]',
            'operation' => '[REDACTED]',
            'remote_id' => '[REDACTED]',
            'local_reference' => '[REDACTED]',
            'snapshot' => '[REDACTED]',
        ];
    }
}
