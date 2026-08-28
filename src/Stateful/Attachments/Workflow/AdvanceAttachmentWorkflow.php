<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentCommand;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;

final readonly class AdvanceAttachmentWorkflow
{
    public function __construct(
        private FinalizeAttachmentOperationFactory $finalize,
        private OperationCoordinator $operations,
        private AttachmentWorkflowStore $workflows,
    ) {}

    public function advance(PendingAttachmentFinalize $pending): OperationReceipt
    {
        $receipt = $this->operations->accept($this->finalize->make(
            new FinalizeAttachmentCommand(
                $pending->connectionKey,
                $pending->remoteId,
                $pending->resourceId,
                $pending->uploadOperationId,
                $pending->artifactId,
                $pending->fileName,
                $pending->object,
                $pending->expectedAttachmentsCount,
                $pending->revisionKeyHmacSha256,
                $pending->sourceSnapshotHmacSha256,
            ),
            IntegrationContext::make('attachment:'.$pending->uploadOperationId->value),
        ));

        $this->workflows->linkFinalize($pending->uploadOperationId, $receipt->operationId);

        return $receipt;
    }
}
