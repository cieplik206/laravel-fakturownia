<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

interface ReadSleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
