<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Exceptions;

use RuntimeException;

final class InvoiceDraftInvalid extends RuntimeException
{
    public function __construct(public readonly int $issueCount)
    {
        parent::__construct("Invoice draft failed validation with {$issueCount} issue(s).");
    }
}
