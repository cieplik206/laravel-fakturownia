<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use InvalidArgumentException;

final readonly class InvoicePdfReadyResult implements OperationResult
{
    use RejectsNativeSerialization;

    public function __construct(
        public ArtifactId $artifactId,
        public InvoiceResourceId $resourceId,
        public string $revisionKeyHmac,
        public string $sourceSnapshotFingerprintHmac,
        public ArtifactObjectDescriptor $object,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $revisionKeyHmac) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotFingerprintHmac) !== 1
            || $object->mimeType !== StagedInvoicePdf::MimeType
            || ! $artifactId->equals(ArtifactId::fromRevisionHmac($revisionKeyHmac))) {
            throw new InvalidArgumentException('The invoice PDF ready result is invalid.');
        }
    }

    public function resultType(): string
    {
        return InvoicePdfReadyResultCodec::ResultType;
    }
}
