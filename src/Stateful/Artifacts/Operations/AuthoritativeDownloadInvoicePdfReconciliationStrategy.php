<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeDownloadInvoicePdfReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(
        private InvoicePdfSourceReader $source,
        private ContentAddressedArtifactStore $store,
        private InvoicePdfStager $stager,
    ) {}

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        $command = (new DownloadInvoicePdfPayloadCodec)->decode($context->payload());

        if ($context->scope()->provider->value !== 'fakturownia'
            || $context->operationType()->value !== DownloadInvoicePdfOperationDefinitionProvider::OperationType
            || ! $command->connectionKey->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.invoice_pdf.scope_mismatch');
        }

        try {
            $staged = $this->stager->stage(
                $this->source->open($command->connectionKey, $command->remoteId),
                $command->maximumBytes,
            );
            $object = $this->store->inspect($staged->contentAddress);
            $staged->content->close();
        } catch (Throwable $failure) {
            if (isset($staged)) {
                $staged->content->close();
            }

            if ($failure instanceof DownloadInvoicePdfOperationFailure
                && in_array($failure->disposition, [
                    FailureDisposition::Permanent,
                    FailureDisposition::ManualReview,
                ], true)) {
                return AuthoritativeReconciliationOutcome::ambiguousMatches(
                    $this->manualFailure(),
                    'fakturownia.invoice_pdf.source_changed',
                );
            }

            return AuthoritativeReconciliationOutcome::inconclusive(
                'fakturownia.invoice_pdf.reconciliation_temporarily_unavailable',
            );
        }

        $observation = new CanonicalObject([
            'content_address' => (string) $staged->contentAddress,
            'mime_type' => StagedInvoicePdf::MimeType,
            'size_bytes' => $staged->sizeBytes,
        ]);

        if (! $object instanceof ArtifactObjectDescriptor) {
            return AuthoritativeReconciliationOutcome::absentConclusive(
                new SafeOperationFailure(
                    'fakturownia_invoice_pdf_object_absent',
                    'The exact content-addressed PDF object is absent after reconciliation.',
                ),
                'fakturownia.invoice_pdf.object_absent',
                $observation,
            );
        }

        if (! $object->contentAddress->equals($staged->contentAddress)
            || $object->mimeType !== StagedInvoicePdf::MimeType
            || $object->sizeBytes !== $staged->sizeBytes) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualFailure(),
                'fakturownia.invoice_pdf.object_conflict',
                $observation,
            );
        }

        return AuthoritativeReconciliationOutcome::foundExact(
            new InvoicePdfReadyResult(
                ArtifactId::fromRevisionHmac($command->revisionKey->hex),
                $command->resourceId,
                $command->revisionKey->hex,
                $command->sourceSnapshotFingerprint->hex,
                $object,
            ),
            'fakturownia.invoice_pdf.object_found',
            $observation,
        );
    }

    private function manualFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_invoice_pdf_manual_review',
            'The invoice PDF artifact requires operator review before another generation is attempted.',
        );
    }
}
