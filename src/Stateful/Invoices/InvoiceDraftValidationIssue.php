<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

final readonly class InvoiceDraftValidationIssue
{
    public function __construct(
        public string $path,
        public string $code,
    ) {}
}
