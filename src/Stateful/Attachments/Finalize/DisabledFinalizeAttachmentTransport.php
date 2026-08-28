<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\FinalizeAttachmentTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledFinalizeAttachmentTransport implements FinalizeAttachmentTransport
{
    public function finalize(
        ConnectionKey $connection,
        string $remoteId,
        string $fileName,
        EffectBoundary $boundary,
    ): void {
        throw AttachmentOperationFailure::finalizeDisabled();
    }
}
