<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use LogicException;

final class ResourceArtifactContentStream extends ArtifactContentStream
{
    /** @param resource $resource */
    public function __construct(private $resource) {}

    public function read(int $maximumBytes): string
    {
        if ($maximumBytes < 1 || $maximumBytes > 1_048_576) {
            throw new LogicException('Artifact stream reads must be between 1 byte and 1 MiB.');
        }

        if (! is_resource($this->resource)) {
            throw new LogicException('Artifact stream is closed.');
        }

        $bytes = fread($this->resource, $maximumBytes);

        if (! is_string($bytes)) {
            throw new LogicException('Artifact stream cannot be read.');
        }

        return $bytes;
    }

    public function eof(): bool
    {
        return ! is_resource($this->resource) || feof($this->resource);
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }
}
