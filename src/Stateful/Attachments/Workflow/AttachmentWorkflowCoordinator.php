<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Attachments\AttachmentSourceStager;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryCommand;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationFactory;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;

final readonly class AttachmentWorkflowCoordinator
{
    public function __construct(
        private AttachmentSourceStager $sources,
        private UploadAttachmentBinaryOperationFactory $uploads,
        private OperationCoordinator $operations,
    ) {}

    public function attach(AttachInvoiceCommand $command): AttachmentWorkflowReceipt
    {
        $source = $this->sources->stage($command->content, $command->fileName);
        $receipt = $this->operations->accept($this->uploads->make(
            new UploadAttachmentBinaryCommand(
                $command->connectionKey,
                $command->remoteId,
                $command->resourceId,
                $command->localReference,
                $source->fileName,
                $source->object->contentAddress,
                $source->object->mimeType,
                $source->object->sizeBytes,
                $command->expectedAttachmentsCount,
                $command->revisionKeyHmacSha256,
                $command->sourceSnapshotHmacSha256,
            ),
            $command->context,
            $command->priority,
        ));

        return new AttachmentWorkflowReceipt($receipt);
    }
}
