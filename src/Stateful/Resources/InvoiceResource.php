<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshot;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InvoiceResource
{
    use RejectsNativeSerialization;

    public const string ResourceType = 'invoice';

    public const string LocalReferenceType = 'transaction_order';

    public const string CorrectionLocalReferenceType = 'customer_return';

    public const string CostLocalReferenceType = 'cost_invoice';

    public function __construct(
        public InvoiceResourceId $id,
        public ConnectionKey $connectionKey,
        public string $localReferenceType,
        public VersionedHmacDigest $localReferenceHmac,
        public string $remoteId,
        public string $remoteNumber,
        public OperationId $createdByOperationId,
        public OperationId $lastOperationId,
        public InvoiceResourceSnapshot $snapshot,
        public VersionedHmacDigest $snapshotFingerprint,
        public int $rowVersion,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $syncedAt,
        public ?DateTimeImmutable $remoteUpdatedAt = null,
        public ?DateTimeImmutable $deletedRemotelyAt = null,
    ) {
        if ($localReferenceHmac->domain !== LookupHmacDomain::Intent
            || $snapshotFingerprint->domain !== LookupHmacDomain::Payload
            || $rowVersion < 1
            || ! in_array($localReferenceType, self::localReferenceTypes(), true)
            || ! hash_equals($remoteId, $snapshot->remoteId())
            || ! hash_equals($remoteNumber, $snapshot->remoteNumber())) {
            throw new InvalidArgumentException('Invoice resource identity or projection metadata is invalid.');
        }

        $this->assertUtc($createdAt, 'creation');
        $this->assertUtc($lastSeenAt, 'last-seen');
        $this->assertUtc($syncedAt, 'synchronization');

        if ($createdAt > $lastSeenAt || $lastSeenAt > $syncedAt) {
            throw new InvalidArgumentException('Invoice resource timestamps are not monotonic.');
        }

        if ($remoteUpdatedAt !== null) {
            $this->assertUtc($remoteUpdatedAt, 'remote-update');
        }

        if ($deletedRemotelyAt !== null) {
            $this->assertUtc($deletedRemotelyAt, 'remote-deletion');

            if ($deletedRemotelyAt < $lastSeenAt) {
                throw new InvalidArgumentException('Invoice resource remote deletion predates its last observation.');
            }
        }
    }

    /** @return non-empty-list<string> */
    public static function localReferenceTypes(): array
    {
        return [self::LocalReferenceType, self::CorrectionLocalReferenceType, self::CostLocalReferenceType];
    }

    /** @return array{resource_type: string, connection: string, remote_id: string, local_reference: string, snapshot: string} */
    public function __debugInfo(): array
    {
        return [
            'resource_type' => self::ResourceType,
            'connection' => '[REDACTED]',
            'remote_id' => '[REDACTED]',
            'local_reference' => '[REDACTED]',
            'snapshot' => '[REDACTED]',
        ];
    }

    private function assertUtc(DateTimeImmutable $value, string $field): void
    {
        if ($value->getOffset() !== 0) {
            throw new InvalidArgumentException("Invoice resource {$field} timestamp must use UTC.");
        }
    }
}
