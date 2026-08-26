<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactProjectionStore;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use InvalidArgumentException;

final readonly class InvoicePdfOutcomeProjector implements OutcomeProjector
{
    public function __construct(private ArtifactProjectionStore $store) {}

    public function project(OperationView $operation, ExecutionOutcome $outcome): void
    {
        if (! $outcome->result instanceof InvoicePdfReadyResult
            || $operation->operationType()->value !== DownloadInvoicePdfOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('The invoice PDF outcome projector received an unsupported outcome.');
        }

        $command = (new DownloadInvoicePdfPayloadCodec)->decode($operation->payload());
        (new InvoicePdfOutcomeProjectionPlanner)->planResult($operation, $outcome->result);

        $this->store->apply(new ArtifactProjectionPlan(
            $outcome->result->artifactId,
            $command->connectionKey,
            $operation->operationId(),
            $command->resourceId,
            ArtifactType::InvoicePdf,
            $command->revisionKey->hex,
            $command->sourceSnapshotFingerprint->hex,
            $command->sourceKsefOperationId,
            $command->sourceGovernmentId,
            $outcome->result->object,
        ));
    }
}
