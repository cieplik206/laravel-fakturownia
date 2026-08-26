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
use Symfony\Component\Uid\Ulid;

final readonly class InvoiceIssued
{
    use RejectsNativeSerialization;

    public const int EventVersion = 1;

    public const string Provider = 'fakturownia';

    public string $eventId;

    /**
     * Intentionally sealed until the kernel 0.2 after-commit event boundary is available.
     */
    private function __construct(
        string $eventId,
        public OperationId $operationId,
        public ConnectionKey $connectionKey,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public IntegrationContext $context,
        public DateTimeImmutable $occurredAt,
    ) {
        if (! Ulid::isValid($eventId)
            || $remoteId === ''
            || $remoteId !== trim($remoteId)
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $remoteId) === 1
            || $occurredAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Invoice issued event metadata is invalid.');
        }

        $this->eventId = (string) Ulid::fromString($eventId);
    }

    /** @return array{event_id: string, provider: string, operation: string, connection: string, resource: string, remote_id: string, context: string} */
    public function __debugInfo(): array
    {
        return [
            'event_id' => $this->eventId,
            'provider' => self::Provider,
            'operation' => (string) $this->operationId,
            'connection' => '[REDACTED]',
            'resource' => (string) $this->resourceId,
            'remote_id' => '[REDACTED]',
            'context' => '[REDACTED]',
        ];
    }
}
