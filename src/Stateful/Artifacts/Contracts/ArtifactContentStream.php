<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Contracts;

use LogicException;

abstract class ArtifactContentStream
{
    /** @phpstan-impure */
    abstract public function read(int $maximumBytes): string;

    abstract public function eof(): bool;

    abstract public function close(): void;

    /** @return array{contents: string} */
    final public function __debugInfo(): array
    {
        return ['contents' => '[REDACTED]'];
    }

    /** @return never */
    final public function __clone()
    {
        throw new LogicException('Artifact content streams cannot be cloned.');
    }

    /** @return never */
    final public function __serialize(): array
    {
        throw new LogicException('Artifact content streams cannot be serialized.');
    }

    /** @param array<never, never> $data */
    final public function __unserialize(array $data): never
    {
        throw new LogicException('Artifact content streams cannot be unserialized.');
    }
}
