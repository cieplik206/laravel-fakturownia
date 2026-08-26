<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Read\Contracts\ReadBodyStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;

final class ReadArtifactContentStream extends ArtifactContentStream
{
    public function __construct(private readonly ReadBodyStream $stream) {}

    public function read(int $maximumBytes): string
    {
        return $this->stream->read($maximumBytes);
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
