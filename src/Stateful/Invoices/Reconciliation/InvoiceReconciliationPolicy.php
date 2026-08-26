<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use InvalidArgumentException;

/** @internal Pure decision-core policy; production wiring never accepts a caller-provided instance. */
final readonly class InvoiceReconciliationPolicy
{
    use RejectsNativeReconciliationObjectTransfer;

    public function __construct(
        public int $visibilityWindowSeconds,
        public int $requiredAbsentConfirmations = 2,
        public int $maximumCandidatesPerScan = 100,
        public int $minimumAbsentConfirmationIntervalSeconds = 120,
        public int $maximumRemoteClockSkewSeconds = 60,
    ) {
        if ($visibilityWindowSeconds < 1) {
            throw new InvalidArgumentException('Invoice visibility window must be positive.');
        }

        if ($requiredAbsentConfirmations < 2 || $requiredAbsentConfirmations > 10) {
            throw new InvalidArgumentException('Invoice absence requires between two and ten confirmations.');
        }

        if ($maximumCandidatesPerScan < 1 || $maximumCandidatesPerScan > 1000) {
            throw new InvalidArgumentException('Invoice reconciliation candidate limit is invalid.');
        }

        if ($minimumAbsentConfirmationIntervalSeconds < 1
            || $minimumAbsentConfirmationIntervalSeconds > 86400) {
            throw new InvalidArgumentException('Invoice absence confirmation interval is invalid.');
        }

        if ($maximumRemoteClockSkewSeconds < 0 || $maximumRemoteClockSkewSeconds > 3600) {
            throw new InvalidArgumentException('Invoice remote clock skew tolerance is invalid.');
        }
    }
}
