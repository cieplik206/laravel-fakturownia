<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Corrections\IssueCorrectionRequestPayload;
use Cieplik206\Fakturownia\Stateful\Corrections\IssuedCorrectionResult;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface IssueCorrectionTransport
{
    public function issue(
        ConnectionKey $connection,
        IssueCorrectionRequestPayload $payload,
        EffectBoundary $boundary,
    ): IssuedCorrectionResult;
}
