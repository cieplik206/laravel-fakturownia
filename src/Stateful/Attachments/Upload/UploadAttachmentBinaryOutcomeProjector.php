<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use InvalidArgumentException;

final readonly class UploadAttachmentBinaryOutcomeProjector implements OutcomeProjector
{
    public function __construct(private AttachmentWorkflowStore $store) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if (! $outcome->result instanceof AttachmentBinaryUploadedResult
            || $operation->operationType()->value !== UploadAttachmentBinaryOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment upload projector received an unsupported outcome.');
        }

        (new UploadAttachmentBinaryOutcomeProjectionPlanner)->planResult($operation, $outcome->result);

        $this->store->applyUpload(new AttachmentUploadProjectionPlan(
            $operation->scope()->connection,
            $operation->operationId(),
            $outcome->result,
            $operation->context(),
        ));
    }
}
