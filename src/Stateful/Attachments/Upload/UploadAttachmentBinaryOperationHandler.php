<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\Contracts\UploadAttachmentBinaryTransport;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use InvalidArgumentException;
use Throwable;

final readonly class UploadAttachmentBinaryOperationHandler implements OperationHandler
{
    public function __construct(
        private ContentAddressedArtifactStore $objects,
        private UploadAttachmentBinaryTransport $transport,
        private UploadAttachmentBinaryPayloadCodec $codec = new UploadAttachmentBinaryPayloadCodec,
    ) {}

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== UploadAttachmentBinaryOperationFactory::OperationType) {
            throw new InvalidArgumentException('Attachment upload handler received an unsupported operation.');
        }

        $command = $this->codec->decode($operation->payload());

        if (! $command->connectionKey->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Attachment upload payload connection does not match the operation scope.');
        }

        $object = $this->objects->inspect($command->contentAddress);

        if (! $object instanceof ArtifactObjectDescriptor
            || ! $object->contentAddress->equals($command->contentAddress)
            || ! hash_equals($object->mimeType, $command->mimeType)
            || $object->sizeBytes !== $command->sizeBytes) {
            throw AttachmentOperationFailure::manualReviewRequired();
        }

        $content = $this->objects->open($command->contentAddress);

        try {
            $this->transport->upload(
                $command->connectionKey,
                $command->remoteId,
                $command->fileName,
                $command->contentAddress,
                $command->sizeBytes,
                $content,
                $operation->effectBoundary(),
            );
        } catch (Throwable $failure) {
            if ($failure instanceof AttachmentOperationFailure) {
                throw $failure;
            }

            throw AttachmentOperationFailure::manualReviewRequired();
        } finally {
            $content->close();
        }

        return new ExecutionOutcome(new AttachmentBinaryUploadedResult(
            $command->remoteId,
            $command->resourceId,
            ArtifactId::fromRevisionHmac($command->revisionKeyHmacSha256),
            $command->fileName,
            $object,
            $command->expectedAttachmentsCount,
            $command->revisionKeyHmacSha256,
            $command->sourceSnapshotHmacSha256,
        ));
    }
}
