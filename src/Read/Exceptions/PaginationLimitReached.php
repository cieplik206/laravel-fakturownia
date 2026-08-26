<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

final class PaginationLimitReached extends FakturowniaReadException
{
    public function __construct(string $operation, public readonly int $maximumPages)
    {
        parent::__construct('The Fakturownia pagination safety limit was reached before a terminal page.', $operation);
    }
}
