<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use InvalidArgumentException;

final readonly class DeleteCostInvoiceCommand
{
    use RejectsNativeSerialization;

    public string $remoteId;

    public function __construct(
        public ConnectionKey $connectionKey,
        string $remoteId,
        public string $localReference,
        public string $operatorReference,
        public string $decisionReference,
        public string $remoteSnapshotHmacSha256,
    ) {
        $this->remoteId = RemoteIdentifier::assert($remoteId);
        self::assertReference($localReference, 'local');
        self::assertReference($operatorReference, 'operator');
        self::assertReference($decisionReference, 'decision');

        if (preg_match('/^[a-f0-9]{64}$/D', $remoteSnapshotHmacSha256) !== 1) {
            throw new InvalidArgumentException('Cost invoice delete snapshot HMAC is invalid.');
        }
    }

    private static function assertReference(string $reference, string $field): void
    {
        if ($reference === ''
            || $reference !== trim($reference)
            || strlen($reference) > 256
            || preg_match('//u', $reference) !== 1
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $reference) === 1) {
            throw new InvalidArgumentException("Cost invoice delete {$field} reference is invalid.");
        }
    }
}
