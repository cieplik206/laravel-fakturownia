<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use InvalidArgumentException;

/** @internal */
final class RemoteIdentifier
{
    public static function assert(string $identifier): string
    {
        if (preg_match('/^[1-9][0-9]{0,39}$/', $identifier) !== 1) {
            throw new InvalidArgumentException('A remote identifier must be a positive decimal string.');
        }

        return $identifier;
    }
}
