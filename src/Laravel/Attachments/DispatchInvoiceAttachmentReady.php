<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Attachments;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentResult;
use Cieplik206\Fakturownia\Stateful\Events\InvoiceAttachmentReady;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class DispatchInvoiceAttachmentReady
{
    public function __construct(
        private AuthoritativeOperationQuery $operations,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    public function handle(OperationTerminalized $event): void
    {
        if ($event->scope->provider->value !== 'fakturownia') {
            return;
        }

        $snapshot = $this->operations->within($event->scope)->find($event->operationId);

        if ($snapshot?->operationType->value !== FinalizeAttachmentOperationFactory::OperationType
            || ! $snapshot->result instanceof FinalizeAttachmentResult) {
            return;
        }

        $result = $snapshot->result;
        $this->events->dispatch(new InvoiceAttachmentReady(
            $event->eventId,
            $event->operationId,
            $result->uploadOperationId,
            $event->scope->connection,
            $result->resourceId,
            $result->remoteId,
            $result->artifactId,
            $result->fileName,
            $snapshot->context,
            $this->clock->now(),
        ));
    }
}
