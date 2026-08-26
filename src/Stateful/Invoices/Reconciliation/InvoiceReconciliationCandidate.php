<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteIdentityScope;
use Cieplik206\Fakturownia\Stateful\Invoices\IssuedInvoiceResult;
use DateTimeImmutable;

/** @internal Diagnostic DTO that is not accepted by a production reconciliation entrypoint. */
final readonly class InvoiceReconciliationCandidate
{
    use RejectsNativeReconciliationObjectTransfer;

    public function __construct(
        public RemoteIdentityScope $scope,
        public bool $income,
        public DateTimeImmutable $remoteCreatedAt,
        public IssuedInvoiceResult $invoice,
    ) {}
}
