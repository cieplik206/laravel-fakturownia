<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class DownloadInvoicePdfOperationFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureDisposition $disposition,
        public readonly SafeOperationFailure $safeFailure,
        public readonly ReconciliationTrigger $reconciliationTrigger = ReconciliationTrigger::Unknown,
    ) {
        parent::__construct($safeFailure->summary);
    }

    public static function capabilityUnavailable(): self
    {
        return new self(
            FailureDisposition::Permanent,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_disabled',
                'The reviewed invoice PDF source and artifact store are not enabled for this application.',
            ),
        );
    }

    public static function requestNotStarted(): self
    {
        return new self(
            FailureDisposition::RequestNotStarted,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_effect_not_started',
                'The invoice PDF artifact effect did not start and may be retried safely.',
            ),
        );
    }

    public static function sourceRejected(): self
    {
        return new self(
            FailureDisposition::Permanent,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_invalid_source',
                'The remote invoice response is not a complete bounded PDF.',
            ),
        );
    }

    public static function outcomeUnknown(): self
    {
        return new self(
            FailureDisposition::Uncertain,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_store_outcome_unknown',
                'The content-addressed artifact write may have completed and requires read-only reconciliation.',
            ),
            ReconciliationTrigger::LostResponse,
        );
    }

    public static function integrityConflict(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_integrity_conflict',
                'The invoice PDF artifact address exists with conflicting immutable metadata.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Invoice PDF operation failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Invoice PDF operation failures cannot be serialized.');
    }
}
