<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLease;
use RuntimeException;

final class CacheArtifactAddressLease extends ArtifactAddressLease
{
    private bool $released = false;

    private bool $ownershipLost = false;

    public function __construct(private readonly WritePdoDatabaseLock $lock) {}

    public function assertOwned(): void
    {
        if ($this->released || $this->ownershipLost) {
            throw new RuntimeException('The artifact address lease is no longer owned.');
        }

        if (! $this->lock->isLocked() || ! $this->lock->isOwnedByCurrentProcess()) {
            $this->ownershipLost = true;

            throw new RuntimeException('The artifact address lease ownership has been lost.');
        }
    }

    public function renewFor(int $minimumOwnedSeconds): void
    {
        $this->assertOwned();

        if ($minimumOwnedSeconds < 1 || $minimumOwnedSeconds > 3_600) {
            throw new RuntimeException('The artifact address lease renewal must be between 1 and 3600 seconds.');
        }

        if ($this->lock->refresh($minimumOwnedSeconds) !== true) {
            $this->ownershipLost = true;

            throw new RuntimeException('The artifact address lease could not be renewed by its owner.');
        }

        $this->assertOwned();
    }

    public function release(): void
    {
        if ($this->released || $this->ownershipLost) {
            return;
        }

        if ($this->lock->release() !== true) {
            throw new RuntimeException('The artifact address lease could not be released by its owner.');
        }

        $this->released = true;
    }
}
