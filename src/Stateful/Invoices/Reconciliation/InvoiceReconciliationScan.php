<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use InvalidArgumentException;

/** @internal Diagnostic DTO that is not accepted by a production reconciliation entrypoint. */
final readonly class InvoiceReconciliationScan
{
    use RejectsNativeReconciliationObjectTransfer;

    /** @var list<InvoiceReconciliationCandidate> */
    public array $candidates;

    /** @param array<mixed> $candidates */
    private function __construct(
        public bool $complete,
        array $candidates,
    ) {
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof InvoiceReconciliationCandidate) {
                throw new InvalidArgumentException('Invoice reconciliation scan contains an invalid candidate.');
            }
        }

        if (! $complete && $candidates !== []) {
            throw new InvalidArgumentException('An incomplete invoice reconciliation scan cannot publish candidates.');
        }

        $this->candidates = array_values($candidates);
    }

    /** @param array<mixed> $candidates */
    public static function complete(array $candidates = []): self
    {
        return new self(true, $candidates);
    }

    public static function incomplete(): self
    {
        return new self(false, []);
    }
}
