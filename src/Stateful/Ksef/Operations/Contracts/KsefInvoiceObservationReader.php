<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface KsefInvoiceObservationReader
{
    public function observe(ConnectionKey $connectionKey, string $remoteId): KsefInvoiceObservation;
}
