<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Events;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InvoiceKsefAccepted
{
    use RejectsNativeSerialization;

    public const int EventVersion = 1;

    public function __construct(
        public OperationId $eventId,
        public OperationId $operationId,
        public ConnectionKey $connectionKey,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public string $governmentId,
        public IntegrationContext $context,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($remoteId === ''
            || $governmentId === ''
            || strlen($remoteId) > 191
            || strlen($governmentId) > 256
            || $occurredAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The Invoice KSeF accepted event is invalid.');
        }
    }
}
