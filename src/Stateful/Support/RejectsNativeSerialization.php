<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Support;

use LogicException;

trait RejectsNativeSerialization
{
    /** @return never */
    final public function __serialize(): array
    {
        throw new LogicException('This validated value cannot be serialized.');
    }

    /** @param array<never, never> $data */
    final public function __unserialize(array $data): never
    {
        throw new LogicException('This validated value cannot be unserialized.');
    }
}
