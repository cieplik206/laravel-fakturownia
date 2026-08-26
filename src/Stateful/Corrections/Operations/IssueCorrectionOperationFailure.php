<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class IssueCorrectionOperationFailure extends RuntimeException
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
                'fakturownia_correction_write_disabled',
                'The Fakturownia correction write capability is not enabled by reviewed live evidence.',
            ),
        );
    }

    public static function requestNotStarted(): self
    {
        return new self(
            FailureDisposition::RequestNotStarted,
            new SafeOperationFailure(
                'fakturownia_correction_request_not_started',
                'The correction request was not started and may be retried safely.',
            ),
        );
    }

    public static function outcomeUnknown(
        ReconciliationTrigger $trigger = ReconciliationTrigger::LostResponse,
    ): self {
        return new self(
            FailureDisposition::Uncertain,
            new SafeOperationFailure(
                'fakturownia_correction_outcome_unknown',
                'The correction request may have reached Fakturownia and requires exact reconciliation.',
            ),
            $trigger,
        );
    }

    public static function providerRejected(): self
    {
        return new self(
            FailureDisposition::NotApplied,
            new SafeOperationFailure(
                'fakturownia_correction_rejected',
                'Fakturownia definitively rejected the correction without applying it.',
            ),
        );
    }

    public static function manualReviewRequired(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_correction_manual_review',
                'The correction operation requires manual review.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Issue correction operation failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Issue correction operation failures cannot be serialized.');
    }
}
