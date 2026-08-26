<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefSendTransport;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledKsefSendTransport implements KsefSendTransport
{
    public function transmitOnce(
        ConnectionKey $connectionKey,
        string $remoteId,
        EffectBoundary $boundary,
    ): KsefInvoiceObservation {
        throw EnsureAcceptedOperationFailure::capabilityUnavailable();
    }
}
