<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeUploadAttachmentBinaryReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(private ContentAddressedArtifactStore $objects) {}

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        $command = (new UploadAttachmentBinaryPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== UploadAttachmentBinaryOperationFactory::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.attachment.upload.scope_mismatch',
            );
        }

        try {
            $object = $this->objects->inspect($command->contentAddress);
        } catch (Throwable) {
            $object = null;
        }

        $sourceMatches = $object instanceof ArtifactObjectDescriptor
            && $object->contentAddress->equals($command->contentAddress)
            && hash_equals($object->mimeType, $command->mimeType)
            && $object->sizeBytes === $command->sizeBytes;
        $observation = new CanonicalObject(['durable_source_matches' => $sourceMatches]);

        if (! $sourceMatches) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                new SafeOperationFailure(
                    'fakturownia_attachment_source_unavailable',
                    'The durable attachment source is unavailable and another upload is unsafe.',
                ),
                'fakturownia.attachment.upload.source_unavailable',
                $observation,
            );
        }

        return AuthoritativeReconciliationOutcome::inconclusive(
            'fakturownia.attachment.upload.remote_evidence_required',
            $observation,
        );
    }
}
