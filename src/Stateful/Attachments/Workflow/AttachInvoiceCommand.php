<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class AttachInvoiceCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public ConnectionKey $connectionKey,
        public string $remoteId,
        public InvoiceResourceId $resourceId,
        public string $localReference,
        public string $fileName,
        public ArtifactContentStream $content,
        public int $expectedAttachmentsCount,
        public string $revisionKeyHmacSha256,
        public string $sourceSnapshotHmacSha256,
        public IntegrationContext $context,
        public int $priority = 0,
    ) {
        if ($priority < -100 || $priority > 100) {
            throw new InvalidArgumentException('Attachment workflow priority is invalid.');
        }
    }
}
