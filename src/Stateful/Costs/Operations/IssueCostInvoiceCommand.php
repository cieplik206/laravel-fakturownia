<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class IssueCostInvoiceCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public InvoiceDraft $draft,
        public RemoteInvoiceIdentity $identity,
    ) {
        if ($draft->kind !== 'vat' || $draft->income) {
            throw new InvalidArgumentException('Issue cost invoice command supports only expense VAT invoices.');
        }

        if ($identity->scope->documentKind !== $draft->kind
            || $identity->scope->departmentId !== $draft->departmentId) {
            throw new InvalidArgumentException('Issue cost invoice identity scope does not match the draft.');
        }

        if ($identity->transactionOrderReference() === null) {
            throw new InvalidArgumentException('Issue cost invoice requires a stable local cost reference.');
        }
    }
}
