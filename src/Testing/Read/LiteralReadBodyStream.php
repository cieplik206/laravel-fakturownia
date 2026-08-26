<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadBodyStream;
use InvalidArgumentException;
use LogicException;

final class LiteralReadBodyStream implements ReadBodyStream
{
    private int $offset = 0;

    private bool $closed = false;

    public function __construct(
        private readonly string $body,
        private readonly int $maximumChunkBytes = 8192,
    ) {
        if ($maximumChunkBytes < 1 || $maximumChunkBytes > 1_048_576) {
            throw new InvalidArgumentException('The literal stream chunk size is invalid.');
        }
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('The literal stream read length must be positive.');
        }

        if ($this->closed) {
            throw new LogicException('The literal stream is closed.');
        }

        $chunk = substr($this->body, $this->offset, min($length, $this->maximumChunkBytes));
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
