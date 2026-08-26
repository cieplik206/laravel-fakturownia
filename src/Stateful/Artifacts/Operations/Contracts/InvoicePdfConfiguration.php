<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts;

interface InvoicePdfConfiguration
{
    public function maximumBytes(): int;
}
