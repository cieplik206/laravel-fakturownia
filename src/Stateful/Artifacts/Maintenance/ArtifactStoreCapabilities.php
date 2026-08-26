<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactStoreCapabilities
{
    use RejectsNativeSerialization;

    public function __construct(
        public bool $sharedVisibilityVerified,
        public bool $singleExecutionHostVerified,
        public bool $boundedFinalizedListing,
        public bool $reliableObjectAge,
        public bool $conditionalGenerationDelete,
        public int $conditionalDeleteTimeoutSeconds,
    ) {
        if ($conditionalGenerationDelete
            && ($conditionalDeleteTimeoutSeconds < 1 || $conditionalDeleteTimeoutSeconds > 300)) {
            throw new InvalidArgumentException('The conditional artifact delete timeout must be between 1 and 300 seconds.');
        }

        if (! $conditionalGenerationDelete && $conditionalDeleteTimeoutSeconds !== 0) {
            throw new InvalidArgumentException('A store without conditional delete cannot advertise a delete timeout.');
        }
    }
}
