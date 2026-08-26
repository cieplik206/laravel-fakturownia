<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactObjectPage
{
    use RejectsNativeSerialization;

    /** @var list<ArtifactObjectObservation> */
    public array $objects;

    /** @param array<mixed> $objects */
    public function __construct(array $objects, public ?ContentAddress $nextAddress)
    {
        foreach ($objects as $object) {
            if (! $object instanceof ArtifactObjectObservation) {
                throw new InvalidArgumentException('The artifact object page contains an invalid observation.');
            }
        }

        $this->objects = array_values($objects);
    }
}
