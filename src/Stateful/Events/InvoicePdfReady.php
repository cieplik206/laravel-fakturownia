<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Events;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InvoicePdfReady
{
    use RejectsNativeSerialization;

    public const int EventVersion = 1;

    public function __construct(
        public OperationId $eventId,
        public OperationId $operationId,
        public ConnectionKey $connectionKey,
        public ArtifactId $artifactId,
        public InvoiceResourceId $resourceId,
        public ArtifactObjectDescriptor $object,
        public IntegrationContext $context,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($object->mimeType !== 'application/pdf' || $occurredAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The Invoice PDF ready event is invalid.');
        }
    }
}
