<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\Contracts;

use Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation\InvoiceReconciliationPolicy;

interface InvoiceReconciliationConfiguration
{
    public function policy(): InvoiceReconciliationPolicy;
}
