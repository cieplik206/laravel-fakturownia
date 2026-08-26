<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

interface ReadClock
{
    public function unixTime(): int;
}
