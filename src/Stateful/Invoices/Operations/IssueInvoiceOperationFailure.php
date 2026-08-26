<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class IssueInvoiceOperationFailure extends RuntimeException
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
                'fakturownia_invoice_write_disabled',
                'The Fakturownia invoice write capability is not enabled by reviewed live evidence.',
            ),
        );
    }

    public static function requestNotStarted(): self
    {
        return new self(
            FailureDisposition::RequestNotStarted,
            new SafeOperationFailure(
                'fakturownia_invoice_request_not_started',
                'The invoice request was not started and may be retried safely.',
            ),
        );
    }

    public static function outcomeUnknown(
        ReconciliationTrigger $trigger = ReconciliationTrigger::LostResponse,
    ): self {
        return new self(
            FailureDisposition::Uncertain,
            new SafeOperationFailure(
                'fakturownia_invoice_outcome_unknown',
                'The invoice request may have reached Fakturownia and requires reconciliation.',
            ),
            $trigger,
        );
    }

    public static function providerRejected(): self
    {
        return new self(
            FailureDisposition::NotApplied,
            new SafeOperationFailure(
                'fakturownia_invoice_rejected',
                'Fakturownia definitively rejected the invoice without applying it.',
            ),
        );
    }

    public static function manualReviewRequired(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_invoice_manual_review',
                'The invoice operation requires manual review.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Issue invoice operation failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Issue invoice operation failures cannot be serialized.');
    }
}
