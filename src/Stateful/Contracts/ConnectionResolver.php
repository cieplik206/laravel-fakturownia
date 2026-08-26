<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Contracts;

use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use SensitiveParameter;

interface ConnectionResolver
{
    public function resolve(#[SensitiveParameter] ConnectionKey $connectionKey): ConnectionProfile;
}
