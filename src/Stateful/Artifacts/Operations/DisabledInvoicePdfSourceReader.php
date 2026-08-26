<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledInvoicePdfSourceReader implements InvoicePdfSourceReader
{
    public function open(ConnectionKey $connectionKey, string $remoteId): ArtifactContentStream
    {
        throw DownloadInvoicePdfOperationFailure::capabilityUnavailable();
    }
}
