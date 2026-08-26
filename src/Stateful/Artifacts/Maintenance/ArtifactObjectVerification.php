<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactObjectVerification
{
    use RejectsNativeSerialization;

    private function __construct(
        public ?ArtifactObjectObservation $observation,
        public ?ArtifactMaintenanceIssue $issue,
    ) {
        if (($observation === null) === ($issue === null)) {
            throw new InvalidArgumentException('Artifact object verification must be either healthy or failed.');
        }
    }

    public static function healthy(ArtifactObjectObservation $observation): self
    {
        return new self($observation, null);
    }

    public static function failed(ArtifactMaintenanceIssue $issue): self
    {
        return new self(null, $issue);
    }

    public function passes(): bool
    {
        return $this->observation !== null;
    }
}
