<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\Contracts;

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\FakturowniaClient;

interface ClientFactory
{
    public function make(ConnectionConfig $connectionConfig): FakturowniaClient;
}
