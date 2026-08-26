<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class IssueInvoiceCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public InvoiceDraft $draft,
        public RemoteInvoiceIdentity $identity,
    ) {
        if ($draft->kind !== 'vat' || ! $draft->income) {
            throw new InvalidArgumentException('Issue invoice command supports only income VAT invoices.');
        }

        if ($identity->scope->documentKind !== $draft->kind
            || $identity->scope->departmentId !== $draft->departmentId) {
            throw new InvalidArgumentException('Issue invoice identity scope does not match the draft.');
        }
    }
}
