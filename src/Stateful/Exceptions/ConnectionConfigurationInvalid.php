<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Exceptions;

use RuntimeException;
use Throwable;

final class ConnectionConfigurationInvalid extends RuntimeException
{
    public function __construct(
        public readonly ConnectionConfigurationReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Fakturownia connection configuration is invalid ({$reason->value}).", previous: $previous);
    }
}
