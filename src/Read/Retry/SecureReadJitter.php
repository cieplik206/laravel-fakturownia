<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Retry;

use Cieplik206\Fakturownia\Read\Contracts\ReadJitter;
use InvalidArgumentException;

final class SecureReadJitter implements ReadJitter
{
    public function milliseconds(int $maximumMilliseconds): int
    {
        if ($maximumMilliseconds < 0 || $maximumMilliseconds > 120_000) {
            throw new InvalidArgumentException('The read jitter bound is outside the supported range.');
        }

        return random_int(0, $maximumMilliseconds);
    }
}
