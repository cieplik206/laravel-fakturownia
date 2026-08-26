<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadCapabilityGate;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use InvalidArgumentException;
use ReflectionReference;

final readonly class LiteralReadCapabilityGate implements ReadCapabilityGate
{
    /** @var array<string, true> */
    private array $supported;

    /** @param array<array-key, mixed> $supportedCapabilities */
    public function __construct(array $supportedCapabilities)
    {
        $supported = [];

        foreach ($supportedCapabilities as $key => $capability) {
            if (ReflectionReference::fromArrayElement($supportedCapabilities, $key) !== null) {
                throw new InvalidArgumentException('The literal capability list must not contain references.');
            }

            if (! $capability instanceof ReadCapability) {
                throw new InvalidArgumentException('The literal capability list contains an invalid value.');
            }

            $supported[$capability->value] = true;
        }

        $this->supported = $supported;
    }

    public function assertSupported(ReadCapability $capability): void
    {
        if (! isset($this->supported[$capability->value])) {
            throw new UnsupportedCapability($capability);
        }
    }
}
