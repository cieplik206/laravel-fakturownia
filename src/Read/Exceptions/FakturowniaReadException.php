<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

use RuntimeException;

abstract class FakturowniaReadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerRequestId = null,
    ) {
        parent::__construct($message);
    }
}
