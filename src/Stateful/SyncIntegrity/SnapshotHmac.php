<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class SnapshotHmac
{
    use RejectsNativeSerialization;

    public function __construct(
        public int $keyVersion,
        public string $hex,
    ) {
        if ($keyVersion < 1) {
            throw new InvalidArgumentException('The snapshot HMAC key version must be positive.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1) {
            throw new InvalidArgumentException('The snapshot HMAC must use 64 lowercase hexadecimal characters.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->keyVersion === $other->keyVersion
            && hash_equals($this->hex, $other->hex);
    }
}
