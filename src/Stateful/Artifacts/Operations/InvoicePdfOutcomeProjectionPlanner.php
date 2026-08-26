<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use InvalidArgumentException;

final readonly class InvoicePdfOutcomeProjectionPlanner implements OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan
    {
        if (! $input->result instanceof InvoicePdfReadyResult) {
            throw new InvalidArgumentException('The invoice PDF projection planner received an unsupported result.');
        }

        return $this->planResult($input->operation, $input->result);
    }

    public function planResult(OperationView $operation, InvoicePdfReadyResult $result): ProjectionPlan
    {
        if ($operation->operationType()->value !== DownloadInvoicePdfOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('The invoice PDF projection planner received an unsupported operation.');
        }

        $command = (new DownloadInvoicePdfPayloadCodec)->decode($operation->payload());

        if (! $result->artifactId->equals(ArtifactId::fromRevisionHmac($command->revisionKey->hex))
            || ! $result->resourceId->equals($command->resourceId)
            || ! hash_equals($result->revisionKeyHmac, $command->revisionKey->hex)
            || ! hash_equals($result->sourceSnapshotFingerprintHmac, $command->sourceSnapshotFingerprint->hex)) {
            throw new InvalidArgumentException('The invoice PDF result does not match its durable command.');
        }

        return new ProjectionPlan(ArtifactProjectionPlan::SchemaVersion, [
            new ProjectionMutation(
                ArtifactProjectionPlan::TargetId,
                ['artifact_id' => $result->artifactId->value],
                null,
                [
                    'connection_key' => $command->connectionKey->value,
                    'content_address' => (string) $result->object->contentAddress,
                    'mime_type' => $result->object->mimeType,
                    'operation_id' => $operation->operationId()->value,
                    'resource_id' => $result->resourceId->value,
                    'revision_hmac' => $result->revisionKeyHmac,
                    'size_bytes' => $result->object->sizeBytes,
                ],
            ),
        ]);
    }
}
