<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Testing\Read;

use Cieplik206\Fakturownia\Read\Contracts\ReadClock;

final readonly class FrozenReadClock implements ReadClock
{
    public function __construct(private int $unixTime) {}

    public function unixTime(): int
    {
        return $this->unixTime;
    }
}
