<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class UploadAttachmentBinaryCommand
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(
        public ConnectionKey $connectionKey,
        string $remoteId,
        public InvoiceResourceId $resourceId,
        public string $localReference,
        public string $fileName,
        public ContentAddress $contentAddress,
        public string $mimeType,
        public int $sizeBytes,
        public int $expectedAttachmentsCount,
        public string $revisionKeyHmacSha256,
        public string $sourceSnapshotHmacSha256,
    ) {
        $this->remoteId = RemoteIdentifier::assert($remoteId);

        if ($localReference === ''
            || $localReference !== trim($localReference)
            || strlen($localReference) > 256
            || preg_match('//u', $localReference) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $localReference) === 1) {
            throw new InvalidArgumentException('Attachment upload local reference is invalid.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.pdf$/D', $fileName) !== 1
            || ! hash_equals($mimeType, 'application/pdf')
            || $sizeBytes < 9
            || $sizeBytes > 20 * 1_048_576
            || $expectedAttachmentsCount < 0
            || $expectedAttachmentsCount > 10_000
            || preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmacSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotHmacSha256) !== 1) {
            throw new InvalidArgumentException('Attachment upload command is invalid.');
        }
    }
}
