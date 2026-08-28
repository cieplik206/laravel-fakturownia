<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\Contracts\UploadAttachmentBinaryTransport;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class DisabledUploadAttachmentBinaryTransport implements UploadAttachmentBinaryTransport
{
    public function upload(
        ConnectionKey $connection,
        string $remoteId,
        string $fileName,
        ContentAddress $contentAddress,
        int $sizeBytes,
        ArtifactContentStream $content,
        EffectBoundary $boundary,
    ): void {
        throw AttachmentOperationFailure::uploadDisabled();
    }
}
