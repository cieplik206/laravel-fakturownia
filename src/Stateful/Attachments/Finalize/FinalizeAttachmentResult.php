<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

final readonly class FinalizeAttachmentResult implements OperationResult
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $remoteId,
        public InvoiceResourceId $resourceId,
        public OperationId $uploadOperationId,
        public ArtifactId $artifactId,
        public string $fileName,
        public ArtifactObjectDescriptor $object,
        public int $attachmentsCount,
        public string $revisionKeyHmacSha256,
        public string $sourceSnapshotHmacSha256,
    ) {
        if ($remoteId === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.pdf$/D', $fileName) !== 1
            || $attachmentsCount < 1
            || $attachmentsCount > 10_001
            || preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmacSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotHmacSha256) !== 1
            || ! $artifactId->equals(ArtifactId::fromRevisionHmac($revisionKeyHmacSha256))) {
            throw new InvalidArgumentException('Attachment finalize result is invalid.');
        }
    }

    public function resultType(): string
    {
        return FinalizeAttachmentResultCodec::ResultType;
    }
}
