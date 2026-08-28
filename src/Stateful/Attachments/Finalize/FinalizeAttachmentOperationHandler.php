<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\FinalizeAttachmentTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;
use Throwable;

final readonly class FinalizeAttachmentOperationHandler implements OperationHandler
{
    public function __construct(
        private FinalizeAttachmentTransport $transport,
        private FinalizeAttachmentPayloadCodec $codec = new FinalizeAttachmentPayloadCodec,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== FinalizeAttachmentOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment finalize handler received an unsupported operation.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Attachment finalize payload connection does not match the operation scope.');
        }

        try {
            $this->transport->finalize(
                $command->connectionKey,
                $command->remoteId,
                $command->fileName,
                $operation->effectBoundary(),
            );
        } catch (Throwable $failure) {
            if ($failure instanceof AttachmentOperationFailure) {
                throw $failure;
            }

            throw AttachmentOperationFailure::manualReviewRequired();
        }

        return new ExecutionOutcome(new FinalizeAttachmentResult(
            $command->remoteId,
            $command->resourceId,
            $command->uploadOperationId,
            $command->artifactId,
            $command->fileName,
            $command->object,
            $command->expectedAttachmentsCount + 1,
            $command->revisionKeyHmacSha256,
            $command->sourceSnapshotHmacSha256,
        ));
    }
}
