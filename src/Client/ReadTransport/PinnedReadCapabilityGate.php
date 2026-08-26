<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport;

use Cieplik206\Fakturownia\Read\Contracts\ReadCapabilityGate;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

/**
 * @internal Capability promotion requires a reviewed code change that pins the
 * exact evidence version and digest. Runtime files and caller allowlists are not
 * authority inputs.
 */
final readonly class PinnedReadCapabilityGate implements ReadCapabilityGate
{
    public function assertSupported(ReadCapability $capability): void
    {
        throw new UnsupportedCapability($capability);
    }
}
