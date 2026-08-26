<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Retry;

use Cieplik206\Fakturownia\Read\Contracts\ReadSleeper;
use InvalidArgumentException;

final class NativeReadSleeper implements ReadSleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0 || $milliseconds > 120_000) {
            throw new InvalidArgumentException('The read retry delay is outside the supported range.');
        }

        if ($milliseconds === 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
