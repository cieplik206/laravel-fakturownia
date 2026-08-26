<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts;

use LogicException;

abstract class ArtifactAddressLease
{
    /** Proves that this exact lease is still owned and throws after ownership loss. */
    abstract public function assertOwned(): void;

    /** Renews this exact lease for at least the requested duration and throws after ownership loss. */
    abstract public function renewFor(int $minimumOwnedSeconds): void;

    /** Releases this exact lease. Implementations must make repeated release calls idempotent. */
    abstract public function release(): void;

    /** @return array{lease: string} */
    final public function __debugInfo(): array
    {
        return ['lease' => '[REDACTED]'];
    }

    /** @return never */
    final public function __clone()
    {
        throw new LogicException('Artifact address leases cannot be cloned.');
    }

    /** @return never */
    final public function __serialize(): array
    {
        throw new LogicException('Artifact address leases cannot be serialized.');
    }

    /** @param array<never, never> $data */
    final public function __unserialize(array $data): never
    {
        throw new LogicException('Artifact address leases cannot be unserialized.');
    }
}
