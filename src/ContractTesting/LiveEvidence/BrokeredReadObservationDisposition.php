<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

enum BrokeredReadObservationDisposition: string
{
    case Observed = 'observed';
    case TransportFailure = 'transport_failure';
}
