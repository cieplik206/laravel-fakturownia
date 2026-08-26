<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class EnsureAcceptedCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public ConnectionKey $connectionKey,
        public InvoiceResourceId $resourceId,
        public string $remoteId,
        public KsefConnectionProfile $profile,
    ) {
        if ($remoteId === ''
            || $remoteId !== trim($remoteId)
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $remoteId) === 1) {
            throw new InvalidArgumentException('The KSeF operation requires a bounded remote invoice ID.');
        }
    }
}
