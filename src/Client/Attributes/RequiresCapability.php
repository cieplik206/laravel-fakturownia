<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\Attributes;

use Attribute;

/**
 * Marks a future remote API method with its capability and sealed request.
 *
 * @internal No remote API may use this contract before the RT-3 secure request
 * boundary and live-evidence gate are implemented.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequiresCapability
{
    /** @param class-string $requestClass */
    public function __construct(
        public string $capabilityId,
        public string $requestClass,
    ) {}
}
