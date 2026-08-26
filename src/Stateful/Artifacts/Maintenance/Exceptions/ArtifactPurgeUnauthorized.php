<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Exceptions;

use RuntimeException;

final class ArtifactPurgeUnauthorized extends RuntimeException
{
    public static function invalidPermit(): self
    {
        return new self('The artifact purge permit is invalid, mismatched, or already consumed.');
    }
}
