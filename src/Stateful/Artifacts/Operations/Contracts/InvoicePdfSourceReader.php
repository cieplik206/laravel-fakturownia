<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface InvoicePdfSourceReader
{
    public function open(ConnectionKey $connectionKey, string $remoteId): ArtifactContentStream;
}
