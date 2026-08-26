<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ArtifactObjectObservation
{
    use RejectsNativeSerialization;

    public function __construct(
        public ArtifactObjectDescriptor $object,
        public DateTimeImmutable $lastModifiedAt,
        public string $generationFingerprintSha256,
    ) {
        if ($lastModifiedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The artifact object observation time must use UTC.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $generationFingerprintSha256) !== 1) {
            throw new InvalidArgumentException('The artifact object generation fingerprint must use lowercase hexadecimal.');
        }
    }
}
