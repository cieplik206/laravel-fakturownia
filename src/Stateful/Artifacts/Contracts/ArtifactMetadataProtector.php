<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Artifacts\ProtectedArtifactMetadata;

interface ArtifactMetadataProtector
{
    public function protect(ArtifactProjectionPlan $plan, string $purpose, string $plaintext): ProtectedArtifactMetadata;

    public function recover(
        ArtifactProjectionPlan $plan,
        string $purpose,
        ProtectedArtifactMetadata $metadata,
    ): string;
}
