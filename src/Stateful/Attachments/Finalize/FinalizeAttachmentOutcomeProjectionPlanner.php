<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentFinalizeProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class FinalizeAttachmentOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public const int SchemaVersion = 1;

    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof FinalizeAttachmentResult) {
            throw new InvalidArgumentException('Attachment finalize projection planner received an unsupported result.');
        }

        return $this->planResult($input->operation, $input->result);
    }

    public function planResult(OperationView $operation, FinalizeAttachmentResult $result): ProjectionPlan
    {
        if ($operation->operationType()->value !== FinalizeAttachmentOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment finalize projection planner received an unsupported operation.');
        }

        $command = (new FinalizeAttachmentPayloadCodec)->decode($operation->payload());

        if (! hash_equals($command->remoteId, $result->remoteId)
            || ! $command->resourceId->equals($result->resourceId)
            || ! $command->uploadOperationId->equals($result->uploadOperationId)
            || ! $command->artifactId->equals($result->artifactId)
            || ! hash_equals($command->fileName, $result->fileName)
            || ! $command->object->contentAddress->equals($result->object->contentAddress)
            || ! hash_equals($command->object->disk, $result->object->disk)
            || ! hash_equals($command->object->mimeType, $result->object->mimeType)
            || $command->object->sizeBytes !== $result->object->sizeBytes
            || $result->attachmentsCount < $command->expectedAttachmentsCount + 1
            || ! hash_equals($command->revisionKeyHmacSha256, $result->revisionKeyHmacSha256)
            || ! hash_equals($command->sourceSnapshotHmacSha256, $result->sourceSnapshotHmacSha256)) {
            throw new InvalidArgumentException('Attachment finalize result does not match its durable command.');
        }

        return new ProjectionPlan(self::SchemaVersion, [
            new ProjectionMutation(
                ArtifactProjectionPlan::TargetId,
                ['artifact_id' => $result->artifactId->value],
                null,
                [
                    'connection_key' => $command->connectionKey->value,
                    'content_address' => (string) $result->object->contentAddress,
                    'operation_id' => $operation->operationId()->value,
                    'resource_id' => $result->resourceId->value,
                    'revision_hmac' => $result->revisionKeyHmacSha256,
                ],
            ),
            new ProjectionMutation(
                AttachmentFinalizeProjectionPlan::TargetId,
                ['upload_operation_id' => $result->uploadOperationId->value],
                null,
                ['finalize_operation_id' => $operation->operationId()->value],
            ),
        ]);
    }
}
