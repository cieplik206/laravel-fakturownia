<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class UploadAttachmentBinaryOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public const int SchemaVersion = 1;

    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof AttachmentBinaryUploadedResult) {
            throw new InvalidArgumentException('Attachment upload projection planner received an unsupported result.');
        }

        return $this->planResult($input->operation, $input->result);
    }

    public function planResult(OperationView $operation, AttachmentBinaryUploadedResult $result): ProjectionPlan
    {
        if ($operation->operationType()->value !== UploadAttachmentBinaryOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment upload projection planner received an unsupported operation.');
        }

        $command = (new UploadAttachmentBinaryPayloadCodec)->decode($operation->payload());

        if (! hash_equals($command->remoteId, $result->remoteId)
            || ! $command->resourceId->equals($result->resourceId)
            || ! hash_equals($command->fileName, $result->fileName)
            || ! $command->contentAddress->equals($result->object->contentAddress)
            || ! hash_equals($command->mimeType, $result->object->mimeType)
            || $command->sizeBytes !== $result->object->sizeBytes
            || $command->expectedAttachmentsCount !== $result->expectedAttachmentsCount
            || ! hash_equals($command->revisionKeyHmacSha256, $result->revisionKeyHmacSha256)
            || ! hash_equals($command->sourceSnapshotHmacSha256, $result->sourceSnapshotHmacSha256)) {
            throw new InvalidArgumentException('Attachment upload result does not match its durable command.');
        }

        return new ProjectionPlan(self::SchemaVersion, [
            new ProjectionMutation(
                AttachmentUploadProjectionPlan::TargetId,
                ['upload_operation_id' => $operation->operationId()->value],
                null,
                [
                    'connection_key' => $command->connectionKey->value,
                    'content_address' => (string) $result->object->contentAddress,
                    'mime_type' => $result->object->mimeType,
                    'operation_id' => $operation->operationId()->value,
                    'resource_id' => $result->resourceId->value,
                    'revision_hmac' => $result->revisionKeyHmacSha256,
                    'size_bytes' => $result->object->sizeBytes,
                ],
            ),
        ]);
    }
}
