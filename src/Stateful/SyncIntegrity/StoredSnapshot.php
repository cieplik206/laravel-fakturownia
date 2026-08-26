<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class StoredSnapshot
{
    use RejectsNativeSerialization;

    public function __construct(
        public SnapshotAttestation $attestation,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $tombstonedAt = null,
    ) {
        $this->assertUtc($firstSeenAt, 'first seen');
        $this->assertUtc($lastSeenAt, 'last seen');

        if ($lastSeenAt < $firstSeenAt) {
            throw new InvalidArgumentException('The snapshot last-seen time cannot precede its first-seen time.');
        }

        if ($tombstonedAt !== null) {
            $this->assertUtc($tombstonedAt, 'tombstone');

            if ($tombstonedAt < $lastSeenAt) {
                throw new InvalidArgumentException('The snapshot tombstone cannot precede its last-seen time.');
            }
        }
    }

    public function isTombstoned(): bool
    {
        return $this->tombstonedAt !== null;
    }

    private function assertUtc(DateTimeImmutable $time, string $field): void
    {
        if ($time->getOffset() !== 0) {
            throw new InvalidArgumentException("The snapshot {$field} time must use UTC.");
        }
    }
}
