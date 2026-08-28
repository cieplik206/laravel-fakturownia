<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Attachments;

use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AttachmentBinaryUploadedResult;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AdvanceAttachmentWorkflow;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\PendingAttachmentFinalize;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;

final readonly class DispatchAttachmentFinalize
{
    public function __construct(
        private AuthoritativeOperationQuery $operations,
        private AdvanceAttachmentWorkflow $workflows,
    ) {}

    public function handle(OperationTerminalized $event): void
    {
        if ($event->scope->provider->value !== 'fakturownia') {
            return;
        }

        $snapshot = $this->operations->within($event->scope)->find($event->operationId);

        if ($snapshot?->operationType->value !== UploadAttachmentBinaryOperationFactory::OperationType
            || ! $snapshot->result instanceof AttachmentBinaryUploadedResult) {
            return;
        }

        $result = $snapshot->result;
        $this->workflows->advance(new PendingAttachmentFinalize(
            $event->scope->connection,
            $event->operationId,
            $result->resourceId,
            $result->remoteId,
            $result->artifactId,
            $result->fileName,
            $result->object,
            $result->expectedAttachmentsCount,
            $result->revisionKeyHmacSha256,
            $result->sourceSnapshotHmacSha256,
        ));
    }
}
