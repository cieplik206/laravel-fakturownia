<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLease;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class CacheArtifactAddressLock implements ArtifactAddressLock
{
    public function __construct(
        private Connection $connection,
        private string $lockTable,
        private int $leaseSeconds = 30,
        private int $waitSeconds = 5,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}\.[a-z][a-z0-9_]{0,62}$/D', $lockTable) !== 1) {
            throw new InvalidArgumentException('The artifact address lock table must be schema-qualified.');
        }

        if ($leaseSeconds < 1 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('The artifact address lock lease must be between 1 and 3600 seconds.');
        }

        if ($waitSeconds < 0 || $waitSeconds > 60) {
            throw new InvalidArgumentException('The artifact address lock wait must be between 0 and 60 seconds.');
        }
    }

    public function acquire(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactAddressLease {
        $lock = new WritePdoDatabaseLock(
            $this->connection,
            $this->lockTable,
            $this->key($storageNamespace, $contentAddress),
            $this->leaseSeconds,
            null,
            [0, 100],
        );

        if ($lock->block($this->waitSeconds) !== true) {
            throw new RuntimeException('The artifact address lock returned an invalid acquisition result.');
        }

        if (! $lock->isLocked() || ! $lock->isOwnedByCurrentProcess()) {
            throw new RuntimeException('The cache provider did not prove ownership of the artifact address lock.');
        }

        return new CacheArtifactAddressLease($lock);
    }

    private function key(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): string {
        return 'fakturownia:artifact-address:'.hash(
            'sha256',
            $storageNamespace->fingerprintSha256()."\0".(string) $contentAddress,
        );
    }
}
