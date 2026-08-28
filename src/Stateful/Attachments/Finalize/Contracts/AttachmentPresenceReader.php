<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\AttachmentPresenceObservation;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

interface AttachmentPresenceReader
{
    public function observe(ConnectionKey $connection, string $remoteId): ?AttachmentPresenceObservation;
}
