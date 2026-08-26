<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources\Contracts;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;

interface InvoiceResourceProjectionStore
{
    /**
     * Applies the mapping inside the kernel-owned terminal unit of work.
     *
     * Implementations must atomically enforce remote and local uniqueness, protect the canonical
     * snapshot, reject collisions, and return the existing row for an exact idempotent replay.
     */
    public function apply(InvoiceResourceProjectionPlan $plan): InvoiceResource;
}
