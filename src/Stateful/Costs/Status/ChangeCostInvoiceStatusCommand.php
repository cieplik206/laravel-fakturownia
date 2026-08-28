<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class ChangeCostInvoiceStatusCommand
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(
        public ConnectionKey $connectionKey,
        string $remoteId,
        public string $localReference,
        public OpenInvoiceStatus $expectedStatus,
        public OpenInvoiceStatus $targetStatus,
        public string $remoteSnapshotHmacSha256,
    ) {
        $this->remoteId = RemoteIdentifier::assert($remoteId);

        if ($localReference === ''
            || $localReference !== trim($localReference)
            || strlen($localReference) > 256
            || preg_match('//u', $localReference) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $localReference) === 1) {
            throw new InvalidArgumentException('Cost invoice status local reference is invalid.');
        }

        if (hash_equals($expectedStatus->raw, $targetStatus->raw)) {
            throw new InvalidArgumentException('Cost invoice status mutation must change the status.');
        }

        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $expectedStatus->raw) === 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $targetStatus->raw) === 1) {
            throw new InvalidArgumentException('Cost invoice status mutation contains an unsafe status value.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $remoteSnapshotHmacSha256) !== 1) {
            throw new InvalidArgumentException('Cost invoice status snapshot HMAC is invalid.');
        }
    }
}
