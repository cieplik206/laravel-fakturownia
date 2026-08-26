<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class EnsureAcceptedOperationFailure extends RuntimeException
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
                'fakturownia_ksef_send_disabled',
                'The reviewed KSeF send transport is not enabled for this application.',
            ),
        );
    }

    public static function requestNotStarted(): self
    {
        return new self(
            FailureDisposition::RequestNotStarted,
            new SafeOperationFailure(
                'fakturownia_ksef_request_not_started',
                'The KSeF send request did not start and may be retried safely.',
            ),
        );
    }

    public static function outcomeUnknown(
        ReconciliationTrigger $trigger = ReconciliationTrigger::LostResponse,
    ): self {
        return new self(
            FailureDisposition::Uncertain,
            new SafeOperationFailure(
                'fakturownia_ksef_outcome_unknown',
                'The KSeF send may have started and requires read-only reconciliation.',
            ),
            $trigger,
        );
    }

    public static function manualReviewRequired(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_ksef_manual_review',
                'The KSeF operation requires manual review without another send.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('KSeF operation failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF operation failures cannot be serialized.');
    }
}
