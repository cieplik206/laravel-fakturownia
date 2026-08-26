<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;

final readonly class SnapshotAttestation
{
    use RejectsNativeSerialization;

    public function __construct(
        public SyncIntegrityScope $scope,
        public SnapshotHmac $remoteIdentity,
        public SnapshotHmac $snapshot,
    ) {
        if ($remoteIdentity->keyVersion !== $snapshot->keyVersion) {
            throw new \InvalidArgumentException('The snapshot attestation HMAC versions must agree.');
        }
    }

    public function keyVersion(): int
    {
        return $this->snapshot->keyVersion;
    }

    public function sameRemoteIdentity(self $other): bool
    {
        return $this->scope->equals($other->scope)
            && $this->remoteIdentity->equals($other->remoteIdentity);
    }

    public function sameSnapshot(self $other): bool
    {
        return $this->sameRemoteIdentity($other)
            && $this->snapshot->equals($other->snapshot);
    }
}
