<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Proformas\ProformaDraft;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class IssueProformaCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public ProformaDraft $draft,
        public RemoteInvoiceIdentity $identity,
    ) {
        if ($identity->scope->documentKind !== 'proforma'
            || $identity->scope->departmentId !== $draft->departmentId) {
            throw new InvalidArgumentException('Issue proforma identity scope does not match the draft.');
        }
    }
}
