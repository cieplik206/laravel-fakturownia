<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ArtifactPurgeDeadline
{
    use RejectsNativeSerialization;

    public DateTimeImmutable $expiresAt;

    public function __construct(
        public DateTimeImmutable $issuedAt,
        public int $maximumDurationSeconds,
    ) {
        if ($issuedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The artifact purge deadline must use UTC.');
        }

        if ($maximumDurationSeconds < 1 || $maximumDurationSeconds > 300) {
            throw new InvalidArgumentException('The artifact purge deadline must be between 1 and 300 seconds.');
        }

        $this->expiresAt = $issuedAt->modify("+{$maximumDurationSeconds} seconds");
    }

    public function hasExpiredAt(DateTimeImmutable $time): bool
    {
        if ($time->getOffset() !== 0) {
            throw new InvalidArgumentException('The artifact purge deadline check must use UTC.');
        }

        return $time >= $this->expiresAt;
    }
}
