<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactDescriptorReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResult;
use Cieplik206\Fakturownia\Stateful\Events\InvoicePdfReady;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class DispatchInvoicePdfReady
{
    public function __construct(
        private AuthoritativeOperationQuery $operations,
        private ArtifactDescriptorReader $artifacts,
        private ContentAddressedArtifactStore $objects,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    public function handle(OperationTerminalized $event): void
    {
        if ($event->scope->provider->value !== 'fakturownia') {
            return;
        }

        $snapshot = $this->operations->within($event->scope)->find($event->operationId);

        if ($snapshot?->operationType->value !== DownloadInvoicePdfOperationDefinitionProvider::OperationType
            || ! $snapshot->result instanceof InvoicePdfReadyResult) {
            return;
        }

        $artifact = $this->artifacts->findByOperation($event->scope->connection, $event->operationId);

        if ($artifact === null
            || $artifact->status !== ArtifactStatus::Ready
            || $artifact->deletedAt !== null
            || ! hash_equals($artifact->id, $snapshot->result->artifactId->value)
            || ! hash_equals($artifact->resourceId, $snapshot->result->resourceId->value)
            || ! $artifact->object->contentAddress->equals($snapshot->result->object->contentAddress)) {
            return;
        }

        $actual = $this->objects->inspect($artifact->object->contentAddress);

        if (! $actual instanceof ArtifactObjectDescriptor
            || ! hash_equals($actual->disk, $artifact->object->disk)
            || ! $actual->contentAddress->equals($artifact->object->contentAddress)
            || ! hash_equals($actual->mimeType, $artifact->object->mimeType)
            || $actual->sizeBytes !== $artifact->object->sizeBytes) {
            return;
        }

        $this->events->dispatch(new InvoicePdfReady(
            $event->eventId,
            $event->operationId,
            $event->scope->connection,
            $snapshot->result->artifactId,
            $snapshot->result->resourceId,
            $artifact->object,
            $snapshot->context,
            $this->clock->now(),
        ));
    }
}
