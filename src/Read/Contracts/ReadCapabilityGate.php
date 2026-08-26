<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

/**
 * @internal Implementations must derive decisions from the package-pinned,
 * verified capability manifest. Caller-provided configuration is not evidence.
 */
interface ReadCapabilityGate
{
    public function assertSupported(ReadCapability $capability): void;
}
