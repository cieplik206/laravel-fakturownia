<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

final readonly class PendingAttachmentFinalize
{
    use RejectsNativeSerialization;

    public function __construct(
        public ConnectionKey $connectionKey,
        public OperationId $uploadOperationId,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public ArtifactId $artifactId,
        public string $fileName,
        public ArtifactObjectDescriptor $object,
        public int $expectedAttachmentsCount,
        public string $revisionKeyHmacSha256,
        public string $sourceSnapshotHmacSha256,
    ) {
        if ($expectedAttachmentsCount < 0 || $expectedAttachmentsCount > 10_000) {
            throw new InvalidArgumentException('Pending attachment finalize count is invalid.');
        }
    }
}
