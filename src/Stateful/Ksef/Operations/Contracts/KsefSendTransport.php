<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface KsefSendTransport
{
    /**
     * The adapter must open the boundary immediately before one remote send and
     * must never retry that send internally.
     */
    public function transmitOnce(
        ConnectionKey $connectionKey,
        string $remoteId,
        EffectBoundary $boundary,
    ): KsefInvoiceObservation;
}
