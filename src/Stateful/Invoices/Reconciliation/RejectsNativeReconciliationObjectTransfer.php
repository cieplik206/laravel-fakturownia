<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

use LogicException;

/** @internal */
trait RejectsNativeReconciliationObjectTransfer
{
    /** @return never */
    final public function __clone()
    {
        throw new LogicException('Invoice reconciliation authority objects cannot be cloned.');
    }

    /** @return never */
    final public function __serialize(): array
    {
        throw new LogicException('Invoice reconciliation authority objects cannot be serialized.');
    }

    /** @param array<never, never> $data */
    final public function __unserialize(array $data): never
    {
        throw new LogicException('Invoice reconciliation authority objects cannot be unserialized.');
    }
}
