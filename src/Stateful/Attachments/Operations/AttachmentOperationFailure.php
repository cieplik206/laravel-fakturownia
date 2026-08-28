<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Operations;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class AttachmentOperationFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureDisposition $disposition,
        public readonly SafeOperationFailure $safeFailure,
        public readonly ReconciliationTrigger $reconciliationTrigger = ReconciliationTrigger::Unknown,
    ) {
        parent::__construct($safeFailure->summary);
    }

    public static function uploadDisabled(): self
    {
        return new self(
            FailureDisposition::Permanent,
            new SafeOperationFailure(
                'fakturownia_attachment_upload_disabled',
                'The Fakturownia attachment upload capability is not enabled by reviewed live evidence.',
            ),
        );
    }

    public static function finalizeDisabled(): self
    {
        return new self(
            FailureDisposition::Permanent,
            new SafeOperationFailure(
                'fakturownia_attachment_finalize_disabled',
                'The Fakturownia attachment finalize capability is not enabled by reviewed live evidence.',
            ),
        );
    }

    public static function requestNotStarted(): self
    {
        return new self(
            FailureDisposition::RequestNotStarted,
            new SafeOperationFailure(
                'fakturownia_attachment_request_not_started',
                'The attachment request was not started and may be retried safely.',
            ),
        );
    }

    public static function providerRejected(): self
    {
        return new self(
            FailureDisposition::NotApplied,
            new SafeOperationFailure(
                'fakturownia_attachment_rejected',
                'Fakturownia definitively rejected the attachment request without applying it.',
            ),
        );
    }

    public static function outcomeUnknown(): self
    {
        return new self(
            FailureDisposition::Uncertain,
            new SafeOperationFailure(
                'fakturownia_attachment_outcome_unknown',
                'The attachment request may have reached the provider and requires reconciliation.',
            ),
            ReconciliationTrigger::LostResponse,
        );
    }

    public static function manualReviewRequired(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_attachment_manual_review',
                'The attachment operation requires operator review before another write.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Attachment operation failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Attachment operation failures cannot be serialized.');
    }
}
