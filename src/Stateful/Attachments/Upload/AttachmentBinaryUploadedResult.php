<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use InvalidArgumentException;

final readonly class AttachmentBinaryUploadedResult implements OperationResult
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(
        string $remoteId,
        public InvoiceResourceId $resourceId,
        public ArtifactId $artifactId,
        public string $fileName,
        public ArtifactObjectDescriptor $object,
        public int $expectedAttachmentsCount,
        public string $revisionKeyHmacSha256,
        public string $sourceSnapshotHmacSha256,
    ) {
        $this->remoteId = RemoteIdentifier::assert($remoteId);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.pdf$/D', $fileName) !== 1
            || $expectedAttachmentsCount < 0
            || $expectedAttachmentsCount > 10_000
            || preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmacSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotHmacSha256) !== 1
            || ! $artifactId->equals(ArtifactId::fromRevisionHmac($revisionKeyHmacSha256))) {
            throw new InvalidArgumentException('Attachment upload result is invalid.');
        }
    }

    public function resultType(): string
    {
        return AttachmentBinaryUploadedResultCodec::ResultType;
    }
}
