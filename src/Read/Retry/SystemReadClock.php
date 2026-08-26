<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Retry;

use Cieplik206\Fakturownia\Read\Contracts\ReadClock;

final class SystemReadClock implements ReadClock
{
    public function unixTime(): int
    {
        return time();
    }
}
