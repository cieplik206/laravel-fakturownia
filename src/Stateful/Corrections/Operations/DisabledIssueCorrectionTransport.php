<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionRequestPayload;
use Cieplik206\Fakturownia\Stateful\Corrections\IssuedCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\Contracts\IssueCorrectionTransport;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledIssueCorrectionTransport implements IssueCorrectionTransport
{
    public function issue(
        ConnectionKey $connection,
        IssueCorrectionRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedCorrectionResult {
        throw IssueCorrectionOperationFailure::capabilityUnavailable();
    }
}
