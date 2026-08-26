<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport;

use Cieplik206\Fakturownia\Read\Contracts\ReadBodyStream;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use SensitiveParameterValue;

/** @internal */
final class PsrReadBodyStream implements ReadBodyStream
{
    private SensitiveParameterValue $stream;

    private bool $closed = false;

    public function __construct(#[SensitiveParameter] StreamInterface $stream)
    {
        $this->stream = new SensitiveParameterValue($stream);
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('The transport stream read length must be positive.');
        }

        if ($this->closed) {
            throw new LogicException('The transport stream is closed.');
        }

        return $this->stream()->read($length);
    }

    public function eof(): bool
    {
        if ($this->closed) {
            return true;
        }

        return $this->stream()->eof();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stream()->close();
    }

    /** @return array{stream: string, credentials: string} */
    public function __debugInfo(): array
    {
        return ['stream' => '[REDACTED]', 'credentials' => '[REDACTED]'];
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Transport response streams cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Transport response streams cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Transport response streams cannot be unserialized.');
    }

    private function stream(): StreamInterface
    {
        $stream = $this->stream->getValue();

        if (! $stream instanceof StreamInterface) {
            throw new LogicException('The transport response stream is corrupted.');
        }

        return $stream;
    }
}
