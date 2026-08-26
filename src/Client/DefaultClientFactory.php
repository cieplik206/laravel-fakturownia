<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client;

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;

final class DefaultClientFactory implements ClientFactory
{
    public function make(ConnectionConfig $connectionConfig): FakturowniaClient
    {
        return $connectionConfig->createClient();
    }
}
