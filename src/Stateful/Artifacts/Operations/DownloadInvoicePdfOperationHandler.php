<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;
use Throwable;

final readonly class DownloadInvoicePdfOperationHandler implements OperationHandler
{
    public function __construct(
        private InvoicePdfSourceReader $source,
        private ContentAddressedArtifactStore $store,
        private InvoicePdfStager $stager,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== DownloadInvoicePdfOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('Invoice PDF handler received an unsupported operation scope.');
        }

        $command = (new DownloadInvoicePdfPayloadCodec)->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Invoice PDF payload connection does not match the operation scope.');
        }

        try {
            $staged = $this->stager->stage(
                $this->source->open($command->connectionKey, $command->remoteId),
                $command->maximumBytes,
            );
            $existing = $this->store->inspect($staged->contentAddress);

            if ($existing instanceof ArtifactObjectDescriptor) {
                $this->assertExpectedObject($existing, $staged);
            }

            $operation->effectBoundary()->open();
            try {
                $object = $this->store->put($staged->content, StagedInvoicePdf::MimeType);
            } finally {
                $staged->content->close();
            }
            $this->assertExpectedObject($object, $staged);

            return new ExecutionOutcome($this->result($command, $object));
        } catch (Throwable $failure) {
            if (isset($staged)) {
                $staged->content->close();
            }

            if ($failure instanceof DownloadInvoicePdfOperationFailure) {
                throw $failure;
            }

            throw $operation->effectBoundary()->wasOpened()
                ? DownloadInvoicePdfOperationFailure::outcomeUnknown()
                : DownloadInvoicePdfOperationFailure::requestNotStarted();
        }
    }

    private function assertExpectedObject(
        ArtifactObjectDescriptor $object,
        StagedInvoicePdf $staged,
    ): void {
        if (! $object->contentAddress->equals($staged->contentAddress)
            || $object->mimeType !== StagedInvoicePdf::MimeType
            || $object->sizeBytes !== $staged->sizeBytes) {
            throw DownloadInvoicePdfOperationFailure::integrityConflict();
        }
    }

    private function result(
        DownloadInvoicePdfCommand $command,
        ArtifactObjectDescriptor $object,
    ): InvoicePdfReadyResult {
        return new InvoicePdfReadyResult(
            ArtifactId::fromRevisionHmac($command->revisionKey->hex),
            $command->resourceId,
            $command->revisionKey->hex,
            $command->sourceSnapshotFingerprint->hex,
            $object,
        );
    }
}
