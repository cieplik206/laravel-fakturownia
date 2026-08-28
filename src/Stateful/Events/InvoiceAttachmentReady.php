<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Events;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;

final readonly class InvoiceAttachmentReady
{
    use RejectsNativeSerialization;

    public function __construct(
        public OperationId $eventId,
        public OperationId $finalizeOperationId,
        public OperationId $uploadOperationId,
        public ConnectionKey $connectionKey,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public ArtifactId $artifactId,
        public string $fileName,
        public IntegrationContext $context,
        public DateTimeImmutable $occurredAt,
    ) {}
}
