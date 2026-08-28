<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\AttachmentPresenceReader;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeFinalizeAttachmentReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(private AttachmentPresenceReader $reader) {}

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        $command = (new FinalizeAttachmentPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== FinalizeAttachmentOperationFactory::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.attachment.finalize.scope_mismatch');
        }

        try {
            $observation = $this->reader->observe($command->connectionKey, $command->remoteId);
        } catch (Throwable) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.attachment.finalize.read_unavailable');
        }

        if (! $observation instanceof AttachmentPresenceObservation) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.attachment.finalize.presence_unavailable');
        }

        $evidence = new CanonicalObject([
            'attachments_count' => $observation->attachmentsCount,
            'file_name_present' => $observation->contains($command->fileName),
        ]);

        if ($observation->contains($command->fileName)
            && $observation->attachmentsCount >= $command->expectedAttachmentsCount + 1) {
            return AuthoritativeReconciliationOutcome::foundExact(
                new FinalizeAttachmentResult(
                    $command->remoteId,
                    $command->resourceId,
                    $command->uploadOperationId,
                    $command->artifactId,
                    $command->fileName,
                    $command->object,
                    $observation->attachmentsCount,
                    $command->revisionKeyHmacSha256,
                    $command->sourceSnapshotHmacSha256,
                ),
                'fakturownia.attachment.finalize.file_observed',
                $evidence,
            );
        }

        if (! $observation->contains($command->fileName)
            && $observation->attachmentsCount === $command->expectedAttachmentsCount) {
            return AuthoritativeReconciliationOutcome::absentConclusive(
                new SafeOperationFailure(
                    'fakturownia_attachment_finalize_not_applied',
                    'The expected attachment file is absent and the prior attachment count is unchanged.',
                ),
                'fakturownia.attachment.finalize.prior_state_observed',
                $evidence,
            );
        }

        return AuthoritativeReconciliationOutcome::ambiguousMatches(
            new SafeOperationFailure(
                'fakturownia_attachment_finalize_manual_review',
                'The remote attachment set changed without an exact expected filename match.',
            ),
            'fakturownia.attachment.finalize.ambiguous_state',
            $evidence,
        );
    }
}
