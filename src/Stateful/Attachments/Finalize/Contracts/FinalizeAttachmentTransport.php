<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts;

use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface FinalizeAttachmentTransport
{
    public function finalize(
        ConnectionKey $connection,
        string $remoteId,
        string $fileName,
        EffectBoundary $boundary,
    ): void;
}
