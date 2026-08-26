<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Contracts;

interface ConfigurationPublisher
{
    public function publish(bool $force): int;
}
