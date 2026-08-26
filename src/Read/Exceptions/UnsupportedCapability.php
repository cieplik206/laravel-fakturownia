<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Exceptions;

use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final class UnsupportedCapability extends FakturowniaReadException
{
    public function __construct(public readonly ReadCapability $capability)
    {
        parent::__construct('The requested Fakturownia read capability is not enabled.', $capability->value);
    }
}
