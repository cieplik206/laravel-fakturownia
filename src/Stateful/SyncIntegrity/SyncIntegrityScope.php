<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class SyncIntegrityScope
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $connectionKey,
        public string $lane,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $connectionKey) !== 1) {
            throw new InvalidArgumentException('The sync integrity connection key is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $lane) !== 1) {
            throw new InvalidArgumentException('The sync integrity lane is invalid.');
        }
    }

    /** @return array{connection_key: string, lane: string} */
    public function canonical(): array
    {
        return [
            'connection_key' => $this->connectionKey,
            'lane' => $this->lane,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->connectionKey === $other->connectionKey
            && $this->lane === $other->lane;
    }
}
