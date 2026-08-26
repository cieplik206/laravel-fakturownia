<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

interface ReadJitter
{
    public function milliseconds(int $maximumMilliseconds): int;
}
