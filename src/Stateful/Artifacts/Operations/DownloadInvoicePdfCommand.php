<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;

final readonly class DownloadInvoicePdfCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public ConnectionKey $connectionKey,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public VersionedHmacDigest $sourceSnapshotFingerprint,
        public int $sourceRowVersion,
        public ?OperationId $sourceKsefOperationId,
        public ?string $sourceGovernmentId,
        public string $renderingProfile,
        public VersionedHmacDigest $revisionKey,
        public int $generation,
        public int $maximumBytes,
    ) {
        if ($remoteId === ''
            || $remoteId !== trim($remoteId)
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $remoteId) === 1
            || $sourceSnapshotFingerprint->domain !== LookupHmacDomain::Payload
            || $revisionKey->domain !== LookupHmacDomain::Payload
            || $sourceRowVersion < 1
            || $generation < 1
            || $generation > 1_000_000
            || $maximumBytes < 9
            || $maximumBytes > 100 * 1_048_576
            || preg_match('/^[a-z][a-z0-9._:-]{0,127}$/D', $renderingProfile) !== 1
            || (($sourceKsefOperationId === null) !== ($sourceGovernmentId === null))) {
            throw new InvalidArgumentException('The invoice PDF command is invalid.');
        }

        if ($sourceGovernmentId !== null
            && ($sourceGovernmentId === ''
                || $sourceGovernmentId !== trim($sourceGovernmentId)
                || strlen($sourceGovernmentId) > 256
                || preg_match('//u', $sourceGovernmentId) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $sourceGovernmentId) === 1)) {
            throw new InvalidArgumentException('The invoice PDF command government ID is invalid.');
        }
    }
}
