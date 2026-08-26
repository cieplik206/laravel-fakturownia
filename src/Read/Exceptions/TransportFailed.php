<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class TransportFailed extends FakturowniaReadException
{
    public function __construct(string $operation)
    {
        parent::__construct('The Fakturownia read transport failed.', $operation);
    }
}
