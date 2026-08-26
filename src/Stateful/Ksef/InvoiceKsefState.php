<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InvoiceKsefState
{
    use RejectsNativeSerialization;

    public function __construct(
        public InvoiceResourceId $resourceId,
        public ConnectionKey $connectionKey,
        public string $remoteId,
        public OperationId $lastOperationId,
        public OpenKsefStatus $status,
        public ?string $governmentId,
        public int $providerErrorCount,
        public bool $offline,
        public bool $configurationBlocked,
        public bool $overdue,
        public string $observationFingerprint,
        public int $rowVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $overdueAt,
    ) {
        if ($remoteId === ''
            || $providerErrorCount < 0
            || $providerErrorCount > 10_000
            || preg_match('/^[a-f0-9]{64}$/D', $observationFingerprint) !== 1
            || $rowVersion < 1
            || $observedAt->getOffset() !== 0
            || ($acceptedAt?->getOffset() ?? 0) !== 0
            || ($rejectedAt?->getOffset() ?? 0) !== 0
            || ($overdueAt?->getOffset() ?? 0) !== 0) {
            throw new InvalidArgumentException('The durable KSeF invoice state is invalid.');
        }

        if ($status->category() === KsefStatusCategory::Succeeded
            && (! $status->isTerminal($governmentId) || $acceptedAt === null)) {
            throw new InvalidArgumentException('A successful KSeF state requires an accepted government ID.');
        }
    }
}
