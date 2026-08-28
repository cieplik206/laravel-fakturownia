<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactProjectionStore;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use InvalidArgumentException;

final readonly class FinalizeAttachmentOutcomeProjector implements OutcomeProjector
{
    public function __construct(
        private ArtifactProjectionStore $artifacts,
        private AttachmentWorkflowStore $workflows,
    ) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if (! $outcome->result instanceof FinalizeAttachmentResult
            || $operation->operationType()->value !== FinalizeAttachmentOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment finalize projector received an unsupported outcome.');
        }

        $result = $outcome->result;
        $command = (new FinalizeAttachmentPayloadCodec)->decode($operation->payload());
        (new FinalizeAttachmentOutcomeProjectionPlanner)->planResult($operation, $result);

        $this->artifacts->apply(new ArtifactProjectionPlan(
            $result->artifactId,
            $command->connectionKey,
            $operation->operationId(),
            $result->resourceId,
            ArtifactType::Attachment,
            $result->revisionKeyHmacSha256,
            $result->sourceSnapshotHmacSha256,
            null,
            null,
            $result->object,
        ));
        $this->workflows->markFinalized($result->uploadOperationId, $operation->operationId());
    }
}
