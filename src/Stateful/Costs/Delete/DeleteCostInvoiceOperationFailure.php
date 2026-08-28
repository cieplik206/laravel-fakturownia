<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use LogicException;
use RuntimeException;

final class DeleteCostInvoiceOperationFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureDisposition $disposition,
        public readonly SafeOperationFailure $safeFailure,
    ) {
        parent::__construct($safeFailure->summary);
    }

    public static function capabilityUnavailable(): self
    {
        return new self(
            FailureDisposition::Permanent,
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_disabled',
                'The Fakturownia cost invoice delete capability is not enabled by reviewed live evidence.',
            ),
        );
    }

    public static function providerRejected(): self
    {
        return new self(
            FailureDisposition::NotApplied,
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_rejected',
                'Fakturownia definitively rejected the cost invoice delete request.',
            ),
        );
    }

    public static function manualReviewRequired(): self
    {
        return new self(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_manual_review',
                'The cost invoice delete operation requires an audited operator review.',
            ),
        );
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Cost invoice delete failures cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Cost invoice delete failures cannot be serialized.');
    }
}
