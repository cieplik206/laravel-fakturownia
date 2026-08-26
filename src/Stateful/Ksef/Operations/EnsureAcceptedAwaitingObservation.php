<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class EnsureAcceptedAwaitingObservation implements OperationResult
{
    use RejectsNativeSerialization;

    public function resultType(): string
    {
        return 'fakturownia.invoice.ksef.ensure_accepted.awaiting_observation';
    }
}
