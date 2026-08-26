<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class ArtifactObjectDescriptor
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $disk,
        public ContentAddress $contentAddress,
        public string $mimeType,
        public int $sizeBytes,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $disk) !== 1) {
            throw new InvalidArgumentException('The artifact disk name is invalid.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,62}$/D', $mimeType) !== 1) {
            throw new InvalidArgumentException('The artifact MIME type must be canonical and must not contain parameters.');
        }

        if ($sizeBytes < 1) {
            throw new InvalidArgumentException('The artifact size must be positive.');
        }
    }

    public function contentSha256(): string
    {
        return $this->contentAddress->sha256();
    }
}
